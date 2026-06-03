<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactRequest;
use App\Models\ContactInquiry;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function store(StoreContactRequest $request): JsonResponse
    {
        ContactInquiry::create([
            ...$request->validated(),
            'ip_address' => $request->ip(),
        ]);

        return $this->success(null, 'Your message has been received. We will get back to you shortly.');
    }
}
