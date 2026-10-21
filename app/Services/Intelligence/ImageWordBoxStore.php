<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Services\Ocr\OrientedImage;
use App\Services\Ocr\WordBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists image word boxes as ocr_region anchors in their own provenance set.
 *
 * Why its own set rather than SourceProvenance::publish():
 *
 *  - That method publishes the analysis graph, keyed by the document's own
 *    pipeline key, and deactivates whatever else is active for that key. A
 *    second publish under the analysis key would therefore switch the
 *    analysis set off, which is exactly the "citations silently stop
 *    resolving" failure this store must not be able to cause.
 *  - It also requires ownsPipeline(), i.e. a live candidate revision. Word-box
 *    location is optional and may legitimately finish after the revision has
 *    published.
 *
 * So the boxes live in the same tables, under the same kinds the schema
 * already allows ('ocr_region' / 'ocr'), but under a reserved pipeline key.
 * source_provenance_one_active is scoped to (document_id, pipeline_key), so
 * the two sets coexist and neither can deactivate the other.
 *
 * start_offset/end_offset are deliberately left NULL. Tesseract's reading is
 * a *second, independent* reading of the image; it is not an offset into the
 * claude_vision text that the citations quote. Claiming an offset would
 * assert a correspondence nothing has verified — the matcher establishes that
 * correspondence per lookup instead, and tolerates the two readings
 * disagreeing.
 */
class ImageWordBoxStore
{
    public const PIPELINE_KEY = 'image_text_location';

    public const PARSER_VERSION = 'tesseract-wordbox.v1';

    /**
     * Replaces this document's stored boxes in one transaction. Either the whole
     * set is published and active, or nothing changed — there is no intermediate
     * state in which a set exists but is not usable.
     *
     * @param  list<WordBox>  $words  Already filtered by confidence.
     * @return string The new set id.
     */
    public function store(Document $document, OrientedImage $image, array $words): string
    {
        $revision = hash('sha256', (string) $document->extracted_text);
        if ($document->workspace_id === null || $revision === '') {
            throw new \InvalidArgumentException('Incomplete word-box owner.');
        }

        return DB::transaction(function () use ($document, $image, $words, $revision): string {
            // Supersede rather than accumulate: one image has one current reading.
            DB::table('source_provenance_sets')->where('document_id', $document->id)
                ->where('workspace_id', $document->workspace_id)
                ->where('pipeline_key', self::PIPELINE_KEY)->delete();

            $setId = (string) Str::uuid();
            DB::table('source_provenance_sets')->insert([
                'id' => $setId,
                'workspace_id' => $document->workspace_id,
                'document_id' => $document->id,
                'source_revision' => $revision,
                'parser_version' => self::PARSER_VERSION,
                'policy_version' => SourceProvenance::POLICY,
                'graph_hash' => $this->graphHash($image, $words),
                'pipeline_key' => self::PIPELINE_KEY,
                'published' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = [];
            foreach ($words as $index => $word) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'set_id' => $setId,
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    // Zero-padded so lexical order is reading order: the matcher
                    // reads these back ordered by source_key and needs words in
                    // the order tesseract emitted them.
                    'source_key' => sprintf('ocrword:1:%06d', $index),
                    'kind' => 'ocr_region',
                    'modality' => 'ocr',
                    'page' => 1, // A JPG/PNG upload is always exactly one page.
                    'sheet' => null,
                    'structural_path' => null,
                    'start_offset' => null,
                    'end_offset' => null,
                    'source_text' => $word->text,
                    'source_hash' => hash('sha256', $word->text),
                    'geometry' => json_encode($this->geometry($image, $word), JSON_THROW_ON_ERROR),
                    'navigation_metadata' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('source_anchors')->insert($batch);
            }

            return $setId;
        });
    }

    /** The active set for this document's current extracted text, or null. */
    public function activeSetId(Document $document): ?string
    {
        return DB::table('source_provenance_sets')
            ->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('pipeline_key', self::PIPELINE_KEY)
            ->where('parser_version', self::PARSER_VERSION)
            ->where('policy_version', SourceProvenance::POLICY)
            // Boxes are tied to the text revision whose quotes they must locate.
            // New extracted text means the old reading is no longer answerable.
            ->where('source_revision', hash('sha256', (string) $document->extracted_text))
            ->where('published', true)->where('active', true)
            ->value('id');
    }

    /**
     * Stored words in reading order.
     *
     * @return list<array{text:string,geometry:array}>
     */
    public function words(Document $document, string $setId, int $limit): array
    {
        return DB::table('source_anchors')
            ->where('workspace_id', $document->workspace_id)
            ->where('document_id', $document->id)
            ->where('set_id', $setId)->where('kind', 'ocr_region')
            ->orderBy('source_key')->limit($limit)
            ->get(['source_text', 'geometry'])
            ->map(static fn ($row) => ['text' => (string) $row->source_text,
                'geometry' => json_decode((string) $row->geometry, true) ?: []])
            ->all();
    }

    /**
     * Geometry mirrors the shape SourceProvenance::validGeometry() already
     * accepts, so these anchors read like every other geometric anchor.
     *
     * rotation is 0 because ImageOrienter has already applied the EXIF
     * orientation: width/height are the *oriented* dimensions, and the box is
     * measured in that same space. It is a statement of fact here, not the
     * placeholder it was in TextRoleProvenanceProducer.
     */
    private function geometry(OrientedImage $image, WordBox $word): array
    {
        return [
            'system' => 'image_pixels_top_left',
            'box' => [$word->left, $word->top, $word->width, $word->height],
            'width' => $image->width,
            'height' => $image->height,
            'rotation' => 0,
            'confidence' => $word->confidence,
            'line' => $word->line,
            'exif_orientation' => $image->exifOrientation,
        ];
    }

    private function graphHash(OrientedImage $image, array $words): string
    {
        return hash('sha256', json_encode([
            $image->width, $image->height, $image->exifOrientation,
            array_map(static fn (WordBox $w) => [$w->text, $w->left, $w->top,
                $w->width, $w->height, $w->confidence, $w->line], $words),
        ], JSON_THROW_ON_ERROR));
    }
}
