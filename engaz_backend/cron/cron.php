<?php
// engaz_backend/cron/cron.php
// This script evaluates and expires tenant subscriptions automatically.
// Should be executed daily via Hostinger Cron.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

$db = Database::getInstance()->getConnection();
$today = date('Y-m-d H:i:s');

try {
    $db->beginTransaction();

    // 1. Expire trialing tenants
    $stmt1 = $db->prepare("UPDATE tenants SET status = 'expired' WHERE status = 'trialing' AND trial_ends_at < ?");
    $stmt1->execute([$today]);
    $expiredTrials = $stmt1->rowCount();

    // 2. Expire active subscriptions
    $stmt2 = $db->prepare("UPDATE tenants SET status = 'expired' WHERE status = 'active' AND subscription_ends_at < ?");
    $stmt2->execute([$today]);
    $expiredSubs = $stmt2->rowCount();

    // 3. Clean up expired password reset tokens 
    $stmt3 = $db->prepare("DELETE FROM password_reset_tokens WHERE expires_at < ?");
    $stmt3->execute([$today]);

    $db->commit();

    echo "CRON SUCCESS [" . $today . "]: Expired $expiredTrials trials and $expiredSubs subscriptions.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "CRON ERROR [" . $today . "]: " . $e->getMessage() . "\n";
}
