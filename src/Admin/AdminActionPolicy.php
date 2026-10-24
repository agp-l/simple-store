<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

final class AdminActionPolicy
{
    public const ORDERS = [
        'fio-sync', 'mark-order-paid', 'comgate-refresh', 'gopay-refresh', 'btcpay-refresh',
        'cancel-overdue-bank-order', 'set-order-status', 'save-order-tracking',
        'change-order-shipping', 'correct-order-status', 'correct-order-payment', 'delete-order',
        'packeta-create', 'packeta-courier', 'packeta-reconcile', 'packeta-retry',
        'packeta-cancel', 'packeta-cancel-confirmed', 'packeta-cancel-not-done',
        'carrier-save', 'carrier-register',
    ];

    public const ACCOUNTING = [
        'tax-save-settings', 'tax-save-year-mode', 'tax-add-flat-advance',
        'tax-add-entry', 'tax-add-balance', 'tax-close-balance', 'tax-link-payment',
        'invoice-issue', 'invoice-renumber', 'invoice-email', 'mail-retry',
        'mail-reconcile', 'tax-amend-entry', 'tax-void-entry',
        'tax-order-movement', 'tax-transfer', 'tax-save-record', 'tax-void-record', 'tax-restore-record',
        'invoice-correction', 'invoice-void-correction',
        'evidence-document-save', 'evidence-series-save', 'evidence-invoice-save', 'evidence-commerce-reset',
    ];

    public const RETURNS = ['return-update', 'return-refund', 'return-delete'];

    public const USERS = ['user-create', 'user-update', 'user-password', 'user-delete', 'user-address-save', 'user-address-delete'];

    private const OTHER = [
        'content-import-preview', 'content-import-save',
        'product-prompt-save', 'product-bulk-export', 'product-bulk-prepare', 'product-bulk-step', 'product-bulk-clear', 'product-zip-upload', 'product-zip-step', 'product-zip-clear', 'product-import-preview', 'product-import-save', 'create-content', 'create-translation', 'inline-content', 'create-product',
        'inline-product', 'product-variants', 'set-product-stock', 'category-create', 'category-update', 'category-order',
        'configuration-preview', 'configuration-apply',
        'menu-slot', 'menu-item-save', 'menu-item-remove', 'page-menu',
        'media-library-folder', 'media-library-upload', 'media-library-move', 'media-library-export', 'media-library-import',
        'media-upload', 'media-attach', 'media-delete', 'delete-product',
        'delete-content', 'homepage-product', 'product-selection', 'product-selection-order', 'product-supplier-link', 'product-sale', 'purchase-partner-save', 'product-relation', 'catalog-post',
        'products-sale-preview', 'products-sale-apply', 'products-mass-preview', 'products-mass-apply', 'products-variants-preview', 'products-variants-apply', 'save-checkout-settings', 'fio-cron-rotate', 'save-site-copy',
        'save-mail-settings', 'preview-mail', 'mail-test', 'schema-apply',
        'table-prefix-migrate', 'table-prefix-cleanup',
    ];

    public static function allows(string $action): bool
    {
        return in_array($action, self::OTHER, true) ||
            in_array($action, self::USERS, true) ||
            in_array($action, self::ORDERS, true) ||
            in_array($action, self::ACCOUNTING, true) ||
            in_array($action, self::RETURNS, true);
    }
}
