<?php

namespace App\Support;

/**
 * The shipped copy for every system notification template.
 *
 * This is the single source of truth for defaults: NotificationTemplate::forKey()
 * seeds a missing row from here, and NotificationTemplateSeeder writes them all.
 * The strings are the ones the notification classes used to hardcode, so a fresh
 * install sends exactly what it sent before an admin touches anything.
 *
 * Keys are variant-scoped ('account_approved.dealer') because the copy genuinely
 * differs per context. `group_key` ties the variants back together for the UI.
 *
 * Body lines are newline-separated. A line whose placeholders ALL resolve to empty
 * is dropped at render time - that is how the optional "Reason:" / "Admin Notes:"
 * lines keep their original conditional behaviour without a template language.
 *
 * NOTE: the brand name is written out literally rather than as {{app_name}}.
 * config('app.name') is "Colonial Auto Auction", but these emails have always said
 * "Colonial Auction Services, Inc.", and templating it would silently rebrand every
 * outgoing message. {{app_name}} stays available for admins who want it.
 */
class NotificationTemplateDefaults
{
    public const CHANNELS_ALL    = ['mail', 'database', 'broadcast'];
    public const CHANNELS_IN_APP = ['database', 'broadcast'];
    public const CHANNELS_MAIL   = ['mail'];

    /** Variables available to every template. */
    public const COMMON_VARIABLES = ['first_name', 'app_name'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return array_merge(
            self::accountApproved(),
            self::accountRejected(),
            self::documentStatusUpdated(),
            self::documents(),
            self::poa(),
            self::auction(),
            self::misc(),
        );
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    // -- account_approved -----------------------------------------------------

    private static function accountApproved(): array
    {
        $make = fn (string $variant, string $name, string $subject, string $headline, string $body, string $actionLabel, string $title, string $message) => [
            "account_approved.{$variant}" => [
                'group_key'           => 'account_approved',
                'notification_type'   => 'account_approved',
                'name'                => $name,
                'description'         => 'Sent when an admin approves the account application.',
                'subject'             => $subject,
                'greeting'            => 'Hello {{first_name}}!',
                'email_body'          => "{$headline}\n{$body}\nIf you have any questions, please contact our support team.",
                'action_label'        => $actionLabel,
                'title'               => $title,
                'message'             => $message,
                'available_variables' => self::COMMON_VARIABLES,
                'supported_channels'  => self::CHANNELS_ALL,
            ],
        ];

        return array_merge(
            $make('dealer', 'Account approved - Dealer',
                'Your Dealer Account Has Been Approved',
                'Your dealer account is now active.',
                'Congratulations! Your dealer application has been reviewed and approved. You can now submit vehicles to auction and access all dealer features.',
                'Go to Dashboard',
                'Dealer account approved',
                'Your dealer account is now active. You can submit vehicles to auction.'),
            $make('business', 'Account approved - Business',
                'Your Business Account Has Been Approved',
                'Your business account is now active.',
                'Congratulations! Your business account application has been reviewed and approved. Your account is now fully active.',
                'Go to Dashboard',
                'Business account approved',
                'Your business account is now active.'),
            $make('seller', 'Account approved - Seller',
                'Your Seller Application Has Been Approved',
                'You are now approved to sell on Colonial Auction Services, Inc.',
                'Great news! Your individual seller application has been approved. You can now list vehicles for auction.',
                'Add a Vehicle',
                'Seller application approved',
                'You are now approved to sell on Colonial Auction Services, Inc.'),
            $make('government', 'Account approved - Government',
                'Your Government Account Has Been Approved',
                'Your government account is now active.',
                'Your government/organization account has been reviewed and approved. You now have full access to Colonial Auction Services, Inc.',
                'Go to Dashboard',
                'Government account approved',
                'Your government account is now active.'),
            $make('default', 'Account approved - Default',
                'Your Account Has Been Approved',
                'Your account is now active.',
                'Your account has been reviewed and approved. You can now access all features.',
                'Go to Dashboard',
                'Account approved',
                'Your account has been approved.'),
        );
    }

    // -- account_rejected -----------------------------------------------------

    private static function accountRejected(): array
    {
        $make = fn (string $variant, string $name, string $subject, string $body, string $nextStep, string $title) => [
            "account_rejected.{$variant}" => [
                'group_key'           => 'account_rejected',
                'notification_type'   => 'account_rejected',
                'name'                => $name,
                'description'         => 'Sent when an admin rejects the account application.',
                'subject'             => $subject,
                'greeting'            => 'Hello {{first_name}},',
                // The "Reason" line disappears automatically when {{reason}} is empty.
                'email_body'          => "{$body}\n**Reason:** {{reason}}\n{$nextStep}\nWe appreciate your understanding.",
                'action_label'        => 'Contact Support',
                'title'               => $title,
                'message'             => '{{reason}}',
                'available_variables' => [...self::COMMON_VARIABLES, 'reason'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
        ];

        return array_merge(
            $make('dealer', 'Account rejected - Dealer',
                'Your Dealer Application Was Not Approved',
                'Thank you for applying to become a dealer on Colonial Auction Services, Inc. After reviewing your application, we are unable to approve it at this time.',
                'If you believe this was an error or have additional documentation, please contact our support team.',
                'Dealer application not approved'),
            $make('business', 'Account rejected - Business',
                'Your Business Account Application Was Not Approved',
                'Thank you for submitting your business account application. After reviewing it, we are unable to approve it at this time.',
                'Please contact our support team if you have questions or wish to reapply.',
                'Business account application not approved'),
            $make('seller', 'Account rejected - Seller',
                'Your Seller Application Was Not Approved',
                'Thank you for applying to sell on Colonial Auction Services, Inc. After reviewing your application, we are unable to grant seller access at this time.',
                'Your buyer account remains active. You can contact us to discuss your application or reapply in the future.',
                'Seller application not approved'),
            $make('government', 'Account rejected - Government',
                'Your Government Account Application Was Not Approved',
                'Thank you for your interest in Colonial Auction Services, Inc. After reviewing your government account application, we are unable to approve it at this time.',
                'Please contact our support team if you have questions.',
                'Government account application not approved'),
            $make('default', 'Account rejected - Default',
                'Your Application Was Not Approved',
                'After reviewing your application, we are unable to approve it at this time.',
                'Please contact our support team if you have questions.',
                'Application not approved'),
        );
    }

    // -- document_status_updated ----------------------------------------------

    private static function documentStatusUpdated(): array
    {
        $make = fn (string $variant, string $name, string $subject, string $headline, string $body, string $actionLabel, string $title) => [
            "document_status_updated.{$variant}" => [
                'group_key'           => 'document_status_updated',
                'notification_type'   => 'document_status_updated',
                'name'                => $name,
                'description'         => 'Sent when an admin changes the review status of an uploaded document.',
                'subject'             => $subject,
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "{$headline}\n{$body}\n**Admin Notes:** {{admin_notes}}\nContact our support team if you have any questions.",
                'action_label'        => $actionLabel,
                'title'               => $title,
                'message'             => 'Your {{document_label}} has been updated to: {{status}}.',
                'available_variables' => [...self::COMMON_VARIABLES, 'document_label', 'status', 'admin_notes'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
        ];

        return array_merge(
            $make('approved', 'Document status - Approved',
                'Document Approved: {{document_label}}',
                'Your document has been approved.',
                'Your {{document_label}} has been reviewed and approved by our team.',
                'View Account',
                '{{document_label}} approved'),
            $make('needs_resubmission', 'Document status - Needs resubmission',
                'Action Required: Please Re-upload Your {{document_label}}',
                'Your document needs to be re-submitted.',
                'Your {{document_label}} could not be accepted and requires re-submission.',
                'Re-upload Document',
                '{{document_label}} requires resubmission'),
            $make('rejected', 'Document status - Rejected',
                'Document Not Accepted: {{document_label}}',
                'Your document was not accepted.',
                'Your {{document_label}} has been reviewed and could not be accepted.',
                'View Account',
                '{{document_label}} not accepted'),
            $make('default', 'Document status - Default',
                'Document Status Updated: {{document_label}}',
                'Your document status has been updated.',
                'The status of your {{document_label}} has been updated.',
                'View Account',
                '{{document_label}} status updated'),
        );
    }

    // -- document_needs_resubmission -------------------------------------------

    private static function documents(): array
    {
        return [
            'document_needs_resubmission' => [
                'group_key'           => 'document_needs_resubmission',
                'notification_type'   => 'document_needs_resubmission',
                'name'                => 'Document needs resubmission',
                'description'         => 'Sent when an admin flags a document for resubmission.',
                'subject'             => 'Action Required: Please Resubmit Your Document',
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "Your **{{document_label}}** requires resubmission.\nAdmin notes: {{admin_notes}}\nPlease upload a new version of this document to continue your application.\nIf you have questions, please contact our support team.",
                'action_label'        => 'Upload Documents',
                'title'               => 'Document resubmission required',
                'message'             => 'Your {{document_label}} needs to be resubmitted.',
                'available_variables' => [...self::COMMON_VARIABLES, 'document_label', 'admin_notes'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
        ];
    }

    // -- power of attorney ------------------------------------------------------

    private static function poa(): array
    {
        return [
            'poa_approved' => [
                'group_key'           => 'poa_approved',
                'notification_type'   => 'poa_approved',
                'name'                => 'Power of Attorney approved',
                'description'         => 'Sent when an admin approves a seller POA.',
                'subject'             => 'Your Power of Attorney Has Been Approved',
                'greeting'            => 'Hello {{first_name}}!',
                'email_body'          => "Your Power of Attorney (POA) document has been reviewed and approved.\nYou can now submit vehicles to auction. Head to your vehicle inventory to get started.\nIf you have any questions, please contact our support team.",
                'action_label'        => 'Go to My Vehicles',
                'title'               => 'Power of Attorney approved',
                'message'             => 'Your POA has been approved - you can now submit vehicles to auction.',
                'available_variables' => self::COMMON_VARIABLES,
                'supported_channels'  => self::CHANNELS_ALL,
            ],
            'poa_rejected' => [
                'group_key'           => 'poa_rejected',
                'notification_type'   => 'poa_rejected',
                'name'                => 'Power of Attorney rejected',
                'description'         => 'Sent when an admin rejects a seller POA.',
                'subject'             => 'Action Required: Your Power of Attorney Was Not Approved',
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "Your Power of Attorney (POA) document has been reviewed and could not be approved.\n**Admin Notes:** {{admin_notes}}\nPlease re-upload a corrected POA document to proceed with vehicle submissions.\nContact our support team if you need assistance.",
                'action_label'        => 'Re-upload POA',
                'title'               => 'Power of Attorney not approved',
                'message'             => 'Your POA could not be approved - please re-upload a corrected document.',
                'available_variables' => [...self::COMMON_VARIABLES, 'admin_notes'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
            'poa_revision_requested' => [
                'group_key'           => 'poa_revision_requested',
                'notification_type'   => 'poa_revision_requested',
                'name'                => 'Power of Attorney revision requested',
                'description'         => 'Sent when an admin requests a revision to a seller POA.',
                'subject'             => 'Action Required: Please Revise Your Power of Attorney',
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "Your Power of Attorney (POA) document has been reviewed and a revision is required before it can be approved.\n**What needs to change:** {{admin_notes}}\nPlease submit a revised POA document to proceed with vehicle submissions.\nContact our support team if you need assistance.",
                'action_label'        => 'Revise POA',
                'title'               => 'Power of Attorney revision requested',
                'message'             => 'A revision to your POA has been requested - please submit a corrected document.',
                'available_variables' => [...self::COMMON_VARIABLES, 'admin_notes'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
        ];
    }

    // -- auction ----------------------------------------------------------------

    private static function auction(): array
    {
        return [
            'outbid' => [
                'group_key'           => 'outbid',
                'notification_type'   => 'outbid',
                'name'                => 'Outbid',
                'description'         => 'Sent to a bidder when someone outbids them on a lot.',
                'subject'             => "You've Been Outbid on {{vehicle_name}}",
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "You have been outbid on **{{vehicle_name}}** (Lot {{lot_number}}).\n**New high bid: {{amount}}**\nThe auction is still live - bid now to stay in the race.\nBidding is binding. Good luck!",
                'action_label'        => 'Return to Auction',
                'title'               => 'You have been outbid',
                'message'             => 'You were outbid on {{vehicle_name}} - new high bid is {{amount}}',
                'available_variables' => [...self::COMMON_VARIABLES, 'vehicle_name', 'lot_number', 'amount'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
            'auction_won' => [
                'group_key'           => 'auction_won',
                'notification_type'   => 'auction_won',
                'name'                => 'Auction won',
                'description'         => 'Sent to the winning bidder when a lot closes. The winner email is sent separately by AuctionWonMail, so this template is in-app only.',
                'subject'             => 'Congratulations! You Won {{vehicle_name}}',
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "You won the auction for **{{vehicle_name}}** (Lot {{lot_number}}).\n**Winning bid: {{amount}}**\nOur team will be in touch shortly with next steps for payment and vehicle pickup.\nThank you for participating in the auction!",
                'action_label'        => 'View Won Items',
                'title'               => 'You won the auction!',
                'message'             => 'Congratulations! You won {{vehicle_name}} - Lot {{lot_number}}.',
                'available_variables' => [...self::COMMON_VARIABLES, 'vehicle_name', 'lot_number', 'amount'],
                // No 'mail': NotifyAuctionWinner already sends AuctionWonMail. Adding
                // it here would double-email the winner.
                'supported_channels'  => self::CHANNELS_IN_APP,
            ],
            'bid_placed' => [
                'group_key'           => 'bid_placed',
                'notification_type'   => 'bid_placed',
                'name'                => 'Bid placed on your vehicle',
                'description'         => 'Sent to the seller when the first bid lands on their lot.',
                'subject'             => 'A Bid Has Been Placed on {{vehicle_name}}',
                'greeting'            => 'Hello {{first_name}},',
                'email_body'          => "A bid of **{{amount}}** has been placed on **{{vehicle_name}}** (Lot {{lot_number}}).\nYou will be notified again when the auction concludes.",
                'action_label'        => 'View Auction',
                'title'               => 'A bid was placed on your vehicle',
                'message'             => 'A bid of {{amount}} was placed on {{vehicle_name}}.',
                'available_variables' => [...self::COMMON_VARIABLES, 'vehicle_name', 'lot_number', 'amount'],
                'supported_channels'  => self::CHANNELS_ALL,
            ],
            'lot_awaiting_seller_decision' => [
                'group_key'           => 'lot_awaiting_seller_decision',
                'notification_type'   => 'lot_awaiting_seller_decision',
                'name'                => 'Lot awaiting your decision (If Sale)',
                'description'         => 'Sent to the seller when a lot closes into If Sale. The seller email is sent separately by IfSaleNotificationMail, so this template is in-app only.',
                'subject'             => null,
                'greeting'            => null,
                'email_body'          => null,
                'action_label'        => null,
                'title'               => 'Action required: bid awaiting your approval',
                'message'             => '{{vehicle_name}} closed at {{amount}}. Approve or reject the bid before the deadline.',
                'available_variables' => [...self::COMMON_VARIABLES, 'vehicle_name', 'lot_number', 'amount'],
                'supported_channels'  => self::CHANNELS_IN_APP,
            ],
        ];
    }

    // -- mail-only ---------------------------------------------------------------

    private static function misc(): array
    {
        return [
            'vehicle_going_to_auction' => [
                'group_key'           => 'vehicle_going_to_auction',
                'notification_type'   => 'vehicle_going_to_auction',
                'name'                => 'Watched vehicle going to auction',
                'description'         => 'Sent to users who subscribed to "Notify Me" on a vehicle when it is listed in an auction.',
                'subject'             => 'Vehicle Going to Auction: {{vehicle_name}}',
                'greeting'            => 'Good news!',
                'email_body'          => "A vehicle you're watching has been listed in an upcoming auction.\n**{{vehicle_name}}**\nVIN: {{vin}}\n**Auction:** {{auction_title}}\n**Location:** {{auction_location}}\n**Date:** {{auction_date}}\nYou received this email because you subscribed to notifications for this vehicle.",
                'action_label'        => 'View Vehicle',
                'title'               => null,
                'message'             => null,
                'available_variables' => [...self::COMMON_VARIABLES, 'vehicle_name', 'vin', 'auction_title', 'auction_location', 'auction_date'],
                'supported_channels'  => self::CHANNELS_MAIL,
            ],
            'gov_account_invite' => [
                'group_key'           => 'gov_account_invite',
                'notification_type'   => 'gov_account_invite',
                'name'                => 'Government account invitation',
                'description'         => 'Sent when an admin invites a government/organization account. The invite link is generated by the system.',
                'subject'             => 'You have been invited to join Colonial Auction Services, Inc.',
                'greeting'            => 'Hello!',
                'email_body'          => "An administrator has created a government/organization account for you on Colonial Auction Services, Inc.\nClick the button below to accept your invitation and set up your password.\nIf you were not expecting this invitation, you may safely ignore this email.",
                'action_label'        => 'Accept Invitation',
                'title'               => null,
                'message'             => null,
                'available_variables' => self::COMMON_VARIABLES,
                'supported_channels'  => self::CHANNELS_MAIL,
            ],
        ];
    }
}
