<?php
declare(strict_types=1);

use SimpleStore\Configuration\Installation;
use SimpleStore\Configuration\InstallationSettings;
use SimpleStore\Navigation\PanelUrl;

// Only reached after admin.php has checked administrator authentication and POST CSRF.
$form = [];
$installation = Installation::current();
$installationFormAddress = $installation->baseUrl();
$installationRevision = InstallationSettings::revision($installation);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($action !== 'save-installation-address' || !is_string($_POST['public_base_url'] ?? null) ||
            !is_string($_POST['installation_revision'] ?? null)) throw new InvalidArgumentException('Neplatný formulář adresy obchodu.');
        $installationFormAddress = $_POST['public_base_url'];
        $installationRevision = $_POST['installation_revision'];
        (new InstallationSettings())->save($installationFormAddress, $installationRevision);
        // Stay on the current host/path: the target installation may not have moved yet.
        header('Location: ' . PanelUrl::pretty($adminUrl . '?section=settings&tab=installation&saved=1'), true, 303);
        exit;
    } catch (InvalidArgumentException | RuntimeException $error) {
        $settingsError = $error->getMessage();
    }
}
