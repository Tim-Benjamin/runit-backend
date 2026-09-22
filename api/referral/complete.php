<?php
// api/referral/complete.php
// Called internally from orders/create.php after first order
require_once '../../config/db.php';

function completeReferral($db, $user_id) {
    // Check if this user was referred and referral not yet completed
    $stmt = $db->prepare("
        SELECT r.*, u.referred_by
        FROM referrals r
        JOIN users u ON u.id = r.referred_id
        WHERE r.referred_id = ? AND r.referred_role = 'user'
        AND r.status = 'pending' AND r.reward_given = 0
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $referral = $stmt->fetch();

    if (!$referral) return;

    // Give GH₵2 credit to both parties
    $reward = 2.00;

    // Credit referrer
    $ref_table = $referral['referrer_role'] === 'runner' ? 'runners' : 'users';
    $db->prepare("UPDATE $ref_table SET referral_credit = referral_credit + ? WHERE id = ?")
       ->execute([$reward, $referral['referrer_id']]);

    // Credit referred user
    $db->prepare("UPDATE users SET referral_credit = referral_credit + ? WHERE id = ?")
       ->execute([$reward, $user_id]);

    // Mark completed
    $db->prepare("UPDATE referrals SET status = 'completed', reward_given = 1 WHERE id = ?")
       ->execute([$referral['id']]);

    error_log('[Referral] Completed for user #' . $user_id . ' — both parties credited GH₵' . $reward);
}