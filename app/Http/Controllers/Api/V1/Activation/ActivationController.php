<?php

namespace App\Http\Controllers\Api\V1\Activation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activation\AccountInformationRequest;
use App\Http\Requests\Activation\AccountTypeRequest;
use App\Http\Requests\Activation\BillingInformationRequest;
use App\Http\Requests\Activation\BusinessInformationRequest;
use App\Http\Requests\Activation\DealerInformationRequest;
use App\Http\Requests\Activation\UploadDocumentRequest;
use App\Http\Resources\UserResource;
use App\Models\DealerProfile;
use App\Models\UserDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ActivationController extends Controller
{
    /**
     * POST /api/v1/activation/account-type
     */
    public function accountType(AccountTypeRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        $user->update(['account_type' => $request->account_type]);

        // Sync role to match account type; auto-set account_intent for dealers
        if ($request->account_type === 'dealer') {
            $user->syncRoles(['dealer']);
            // Dealers both buy and sell by definition — set account_intent automatically
            $user->update(['account_intent' => 'buyer_and_seller']);
        } else {
            // individual, business, and government all use the buyer role baseline;
            // business-specific permissions will be layered in a future phase
            $user->syncRoles(['buyer']);
        }

        return $this->success(
            new UserResource($user->fresh()),
            'Account type saved.'
        );
    }

    /**
     * POST /api/v1/activation/account-information
     */
    public function accountInformation(AccountInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        $user->accountInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->only([
                'date_of_birth',
                'address',
                'country',
                'state',
                'county',
                'city',
                'zip_postal_code',
                'driver_license_number',
                'driver_license_expiration_date',
            ])
        );

        return $this->success(
            new UserResource($user->fresh()->load('accountInformation')),
            'Account information saved.'
        );
    }

    /**
     * POST /api/v1/activation/dealer-information
     */
    public function dealerInformation(DealerInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'dealer') {
            return $this->error('This step is only required for dealer accounts.', 422, 'not_a_dealer');
        }

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        $user->dealerInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->only([
                'company_name',
                'owner_name',
                'phone',
                'primary_contact',
                'license_number',
                'license_expiration_date',
                'tax_id_number',
                'dealer_address',
                'dealer_country',
                'dealer_city',
                'dealer_state',
                'dealer_zip_code',
            ])
        );

        return $this->success(
            new UserResource($user->fresh()->load('dealerInformation')),
            'Dealer information saved.'
        );
    }

    /**
     * POST /api/v1/activation/business-information
     */
    public function businessInformation(BusinessInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'business') {
            return $this->error('This step is only required for business accounts.', 422, 'not_a_business');
        }

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        $user->businessInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->only([
                'legal_business_name',
                'dba_name',
                'primary_contact_name',
                'contact_title',
                'phone',
                'office_phone',
                'address',
                'suite',
                'city',
                'state',
                'zip',
                'entity_type',
                'state_of_formation',
            ])
        );

        // Persist account_intent on the user record
        $user->update(['account_intent' => $request->account_intent]);

        return $this->success(
            new UserResource($user->fresh()->load('businessInformation')),
            'Business information saved.'
        );
    }

    /**
     * POST /api/v1/activation/billing-information
     */
    public function billingInformation(BillingInformationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        $user->billingInformation()->updateOrCreate(
            ['user_id' => $user->id],
            $request->only([
                'billing_address',
                'billing_country',
                'billing_city',
                'billing_zip_postal_code',
            ])
        );

        return $this->success(
            new UserResource($user->fresh()->load('billingInformation')),
            'Billing information saved.'
        );
    }

    /**
     * POST /api/v1/activation/upload-documents
     *
     * Accepts multipart/form-data with fields: document_type (id|license), file.
     * Stores on the configured filesystem disk (public or s3).
     */
    public function uploadDocuments(UploadDocumentRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        $file = $request->file('file');
        $disk = config('filesystems.default');
        $type = $request->document_type;

        $path = $file->store(
            "user-documents/{$user->id}/{$type}",
            $disk
        );

        $document = UserDocument::create([
            'user_id'       => $user->id,
            'type'          => $type,
            'file_path'     => $path,
            'disk'          => $disk,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
        ]);

        return $this->success(
            [
                'id'            => $document->id,
                'type'          => $document->type,
                'original_name' => $document->original_name,
                'mime_type'     => $document->mime_type,
                'size_bytes'    => $document->size_bytes,
            ],
            'Document uploaded successfully.',
            201
        );
    }

    /**
     * POST /api/v1/activation/complete
     *
     * Validates all steps are done, then marks the user active (individual)
     * or pending admin approval (dealer).
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isActive()) {
            return $this->error('Account is already fully activated.', 422, 'already_active');
        }

        // ── Pre-condition checks ─────────────────────────────────────────
        $errors = [];

        if (! $user->hasVerifiedEmail()) {
            $errors[] = 'Email address has not been verified.';
        }

        if (! $user->password_set_at) {
            $errors[] = 'Password has not been set.';
        }

        if (! $user->account_type) {
            $errors[] = 'Account type has not been selected.';
        }

        // Individual and dealer require personal account information;
        // business accounts supply business information instead
        if ($user->account_type === 'business') {
            if (! $user->businessInformation) {
                $errors[] = 'Business information is incomplete.';
            }
        } else {
            if (! $user->accountInformation) {
                $errors[] = 'Account information is incomplete.';
            }
        }

        if ($user->account_type === 'dealer' && ! $user->dealerInformation) {
            $errors[] = 'Dealer information is incomplete.';
        }

        if (! $user->billingInformation) {
            $errors[] = 'Billing information is incomplete.';
        }

        if (! $user->documents()->exists()) {
            $errors[] = 'At least one document must be uploaded.';
        }

        if (! empty($errors)) {
            return $this->error(
                'Activation requirements not met.',
                422,
                'activation_incomplete',
                $errors
            );
        }

        // ── Complete activation ──────────────────────────────────────────
        if ($user->account_type === 'dealer') {
            // Sync dealer info into DealerProfile for admin approval flow
            $dealerInfo = $user->dealerInformation;

            DealerProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    // Identity
                    'company_name'                   => $dealerInfo->company_name,
                    'owner_name'                     => $dealerInfo->owner_name,
                    'phone_primary'                  => $dealerInfo->phone,
                    'primary_contact'                => $dealerInfo->primary_contact,
                    // License (field names differ between tables)
                    'dealer_license'                 => $dealerInfo->license_number,
                    'dealer_license_expiration_date' => $dealerInfo->license_expiration_date,
                    'tax_id_number'                  => $dealerInfo->tax_id_number,
                    // Address (field names differ between tables)
                    'dealer_address_line1'           => $dealerInfo->dealer_address,
                    'dealer_city'                    => $dealerInfo->dealer_city,
                    'dealer_state'                   => $dealerInfo->dealer_state,
                    'dealer_postal_code'             => $dealerInfo->dealer_zip_code,
                    'dealer_country'                 => $dealerInfo->dealer_country,
                    // Approval state
                    'packet_accepted_at'             => now(),
                    'approval_status'                => 'pending',
                ]
            );

            $user->update([
                'activation_completed_at' => now(),
                'status'                  => 'pending_activation', // admin must approve
            ]);

            $message = 'Activation complete. Your dealer account is pending admin approval.';
        } elseif ($user->account_type === 'business') {
            $user->update([
                'activation_completed_at' => now(),
                'status'                  => 'pending_activation', // admin must approve
            ]);

            $message = 'Activation complete. Your business account is pending admin approval.';
        } else {
            $user->update([
                'activation_completed_at' => now(),
                'status'                  => 'active',
            ]);

            $message = 'Account activated successfully. Welcome!';
        }

        $user->refresh()->load([
            'accountInformation',
            'dealerInformation',
            'businessInformation',
            'billingInformation',
            'documents',
        ]);

        return $this->success(new UserResource($user), $message);
    }
}
