<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\PaystackClient;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function plans(SubscriptionService $service)
    {
        return $this->success('Plans retrieved.', $service->catalog());
    }

    private function workspace(Request $request): string
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace && $workspace->members()->where('user_id', $request->user()->id)->exists(), 403);

        return $workspace->id;
    }

    public function show(Request $request, EntitlementService $service)
    {
        $workspace = $this->workspace($request);
        $state = $service->summary($workspace);
        $state['can_manage'] = $state['subscription'] && $state['subscription']['user_id'] === $request->user()->id;
        // Do not expose provider payloads or subscription email tokens.
        $state['transactions'] = CreditPurchase::where('workspace_id', $workspace)->where(function ($q) use ($request) {
            $q->where('user_id', $request->user()->id)->orWhereNull('user_id');
        })->latest()->paginate(20, ['id', 'paystack_reference', 'plan_key', 'billing_interval', 'amount_kobo_or_cents', 'currency', 'status', 'paid_at', 'created_at']);

        return $this->success('Billing retrieved.', $state);
    }

    private function owned(Request $request, EntitlementService $service)
    {
        $sub = $service->subscription($this->workspace($request));
        abort_unless($sub && $sub->user_id === $request->user()->id, 403);

        return $sub;
    }

    public function cancel(Request $request, EntitlementService $service, PaystackClient $client)
    {
        $sub = $this->owned($request, $service);
        abort_if($sub->grandfathered, 409, 'Your grandfathered Starter access has no recurring charge to cancel.');
        if (! $sub->cancel_at_period_end) {
            // The external call is deliberately outside database locks.
            if ($sub->provider_subscription_code) {
                try {
                    $client->disableSubscription($sub);
                } catch (\Throwable $e) {
                    report($e);

                    return $this->error('Cancellation could not be confirmed. Please retry.', [], 502);
                }
            } else {
                abort_if(($sub->metadata['renewal_requested'] ?? null) === 'automatic', 409, 'Automatic subscription setup is still being confirmed. Please retry shortly.');
            }
            DB::transaction(function () use ($sub) {
                Workspace::whereKey($sub->workspace_id)->lockForUpdate()->firstOrFail();
                $sub->refresh()->update(['cancel_at_period_end' => true, 'cancelled_at' => now(), 'auto_renews' => false,
                    'status' => $sub->current_period_end?->isFuture() ? 'non_renewing' : 'cancelled']);
            });
        }

        return $this->show($request, $service);
    }

    public function manage(Request $request, EntitlementService $service, PaystackClient $client)
    {
        $sub = $this->owned($request, $service);
        abort_unless($sub->provider_subscription_code, 409, 'No automatic payment method to manage.');
        try {
            return $this->success('Payment management link.', ['url' => $client->managementLink($sub)]);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Payment management is temporarily unavailable.', [], 502);
        }
    }
}
