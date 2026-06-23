<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\UpdatePaymentSettingsRequest;
use App\Http\Resources\Payment\PaymentSettingResource;
use App\Models\PaymentSetting;
use Illuminate\Http\JsonResponse;

class AdminPaymentSettingController extends Controller
{
    /**
     * GET /admin/payment-settings
     */
    public function show(): JsonResponse
    {
        return $this->success(new PaymentSettingResource(PaymentSetting::current()));
    }

    /**
     * PUT /admin/payment-settings
     * Partial update — only validated keys present in the request are written.
     */
    public function update(UpdatePaymentSettingsRequest $request): JsonResponse
    {
        $settings = PaymentSetting::current();
        $settings->update($request->validated());

        return $this->success(
            new PaymentSettingResource($settings->fresh()),
            'Payment settings updated.'
        );
    }
}
