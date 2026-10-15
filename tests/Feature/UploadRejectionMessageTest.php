<?php

namespace Tests\Feature;

use App\Http\Requests\Document\UploadDocumentRequest;
use App\Services\Documents\SupportedDocumentTypes;
use Tests\TestCase;

/**
 * What a user is told when their file's MIME type is not recognised.
 *
 * It said "not recognized as a genuine PDF or DOCX" while XLSX, JPG and PNG
 * were equally accepted, so someone rejected for a renamed spreadsheet was
 * sent after the wrong problem. The message is now built from the same source
 * as the validation rule, so the list a user is told cannot drift from the
 * list actually accepted — which is the property worth testing, rather than
 * the sentence as it reads today.
 */
class UploadRejectionMessageTest extends TestCase
{
    private function mimeMessage(): string
    {
        return (new UploadDocumentRequest())->messages()['file.mimetypes'];
    }

    public function test_the_message_names_every_enabled_type(): void
    {
        $message = $this->mimeMessage();

        foreach (app(SupportedDocumentTypes::class)->enabledTypes() as $type) {
            $this->assertStringContainsString(
                $type,
                $message,
                "the MIME failure message does not name the accepted type {$type}"
            );
        }
    }

    public function test_the_message_names_no_type_that_is_not_accepted(): void
    {
        $message = $this->mimeMessage();
        $enabled = app(SupportedDocumentTypes::class)->enabledTypes();

        // TIFF, CSV and DOC are in the schema and the config but disabled.
        // Naming one would invite a user to retry with a file that is still
        // refused.
        foreach (['TIFF', 'CSV'] as $disabled) {
            if (in_array($disabled, $enabled, true)) {
                continue;
            }
            $this->assertStringNotContainsString($disabled, $message);
        }
    }

    public function test_the_message_follows_the_config_rather_than_a_hardcoded_list(): void
    {
        // Disable XLSX for this assertion only: the message must stop naming
        // it, which is what proves the list is derived and not written out.
        config(['document_types.types.XLSX.enabled' => false]);

        $message = $this->mimeMessage();

        $this->assertStringNotContainsString('XLSX', $message);
        $this->assertStringContainsString('PDF', $message);
    }

    public function test_the_size_message_still_states_the_limit(): void
    {
        // Unchanged by this stage; asserted so the edit to messages() cannot
        // have disturbed the sibling it sits beside.
        $messages = (new UploadDocumentRequest())->messages();

        $expected = round(config('document_processing.max_upload_size_kb') / 1024);
        $this->assertStringContainsString((string) $expected, $messages['file.max']);
        $this->assertStringContainsString('MB', $messages['file.max']);
    }
}
