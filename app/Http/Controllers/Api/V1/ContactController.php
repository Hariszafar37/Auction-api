<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Mail\ContactInquiryReceived;
use App\Models\ContactInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(StoreContactRequest $request): JsonResponse
    {
        $inquiry = ContactInquiry::create([
            ...$request->validated(),
            'ip_address' => $request->ip(),
        ]);

        // Notify the Colonial Auction Services inbox. The inquiry is already
        // persisted, so a mail-transport failure must not fail the request.
        try {
            Mail::to(config('mail.contact_to'))->send(new ContactInquiryReceived($inquiry));
        } catch (\Throwable $e) {
            Log::error('Contact inquiry notification failed to send', [
                'inquiry_id' => $inquiry->id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $this->success(null, 'Your message has been received. We will get back to you shortly.');
    }
}
