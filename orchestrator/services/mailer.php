<?php
/**
 * Mailer Service
 *
 * Sends invitation emails and notifications to users.
 * Supports both SMTP and local mail() function.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/mailer.php';
 *   $mailer = new MailerService();
 *   $mailer->sendUserInvitation($email, $tempPassword, $tenantName);
 *
 * @version 1.0
 * @date 2025-11-14
 */

// Load core libraries if not already loaded
if (!function_exists('db')) {
    require_once __DIR__ . '/../.env.php';
}

class MailerService {
    private $fromEmail;
    private $fromName;
    private $smtpEnabled;

    public function __construct() {
        $this->fromEmail = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@studymate.fr';
        $this->fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'StudyMate';
        $this->smtpEnabled = defined('SMTP_ENABLED') && SMTP_ENABLED === true;
    }

    /**
     * Send user invitation email
     *
     * @param string $email Recipient email
     * @param string $tempPassword Temporary password
     * @param string $tenantName School name
     * @param string $loginUrl Login URL
     * @return bool Success status
     */
    public function sendUserInvitation($email, $tempPassword, $tenantName, $loginUrl = null) {
        $loginUrl = $loginUrl ?? 'https://studymate.fr/login';

        $subject = "Invitation à rejoindre {$tenantName} sur StudyMate";

        $body = $this->renderInvitationTemplate($email, $tempPassword, $tenantName, $loginUrl);

        return $this->send($email, $subject, $body);
    }

    /**
     * Send password reset email
     *
     * @param string $email Recipient email
     * @param string $resetToken Reset token
     * @param string $tenantName School name
     * @return bool Success status
     */
    public function sendPasswordReset($email, $resetToken, $tenantName) {
        $resetUrl = "https://studymate.fr/reset-password?token={$resetToken}";

        $subject = "Réinitialisation de votre mot de passe - {$tenantName}";

        $body = $this->renderPasswordResetTemplate($email, $resetUrl, $tenantName);

        return $this->send($email, $subject, $body);
    }

    /**
     * Send account deactivation notification
     *
     * @param string $email Recipient email
     * @param string $tenantName School name
     * @param string $reason Optional reason
     * @return bool Success status
     */
    public function sendDeactivationNotice($email, $tenantName, $reason = null) {
        $subject = "Votre compte {$tenantName} a été désactivé";

        $body = $this->renderDeactivationTemplate($email, $tenantName, $reason);

        return $this->send($email, $subject, $body);
    }

    /**
     * Send generic email
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body HTML body
     * @param array $headers Optional additional headers
     * @return bool Success status
     */
    public function send($to, $subject, $body, $headers = []) {
        try {
            // Validate email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                logError('Invalid email address', ['email' => $to]);
                return false;
            }

            // Prepare headers
            $defaultHeaders = [
                'From' => "{$this->fromName} <{$this->fromEmail}>",
                'Reply-To' => $this->fromEmail,
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Mailer' => 'StudyMate-Orchestrator/1.0'
            ];

            $allHeaders = array_merge($defaultHeaders, $headers);
            $headerString = $this->buildHeaders($allHeaders);

            // Send email
            if ($this->smtpEnabled) {
                $result = $this->sendViaSMTP($to, $subject, $body, $allHeaders);
            } else {
                $result = mail($to, $subject, $body, $headerString);
            }

            if ($result) {
                logInfo('Email sent successfully', [
                    'to' => $to,
                    'subject' => $subject,
                    'method' => $this->smtpEnabled ? 'SMTP' : 'mail()'
                ]);
            } else {
                logError('Failed to send email', [
                    'to' => $to,
                    'subject' => $subject
                ]);
            }

            return $result;
        } catch (Exception $e) {
            logError('Email sending exception', [
                'error' => $e->getMessage(),
                'to' => $to
            ]);
            return false;
        }
    }

    /**
     * Send via SMTP (placeholder - requires external library like PHPMailer)
     */
    private function sendViaSMTP($to, $subject, $body, $headers) {
        // This is a placeholder. In production, use PHPMailer or similar library
        logWarn('SMTP sending not implemented, falling back to mail()', ['to' => $to]);
        return mail($to, $subject, $body, $this->buildHeaders($headers));
    }

    /**
     * Build headers string
     */
    private function buildHeaders($headers) {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }
        return implode("\r\n", $lines);
    }

    /**
     * Render invitation email template
     */
    private function renderInvitationTemplate($email, $tempPassword, $tenantName, $loginUrl) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #6366f1; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f9fafb; }
        .credentials { background: white; padding: 15px; border-left: 4px solid #6366f1; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 24px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Bienvenue sur StudyMate</h1>
        </div>
        <div class="content">
            <p>Bonjour,</p>
            <p>Vous avez été invité(e) à rejoindre <strong>{$tenantName}</strong> sur StudyMate.</p>

            <div class="credentials">
                <p><strong>Vos identifiants de connexion :</strong></p>
                <p>Email : <strong>{$email}</strong></p>
                <p>Mot de passe temporaire : <strong>{$tempPassword}</strong></p>
            </div>

            <p>⚠️ <strong>Important :</strong> Vous devrez changer ce mot de passe lors de votre première connexion.</p>

            <a href="{$loginUrl}" class="button">Se connecter à StudyMate</a>

            <p>Si vous rencontrez des difficultés, contactez votre administrateur.</p>
        </div>
        <div class="footer">
            <p>StudyMate - Plateforme d'orchestration pédagogique</p>
            <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render password reset template
     */
    private function renderPasswordResetTemplate($email, $resetUrl, $tenantName) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #6366f1; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f9fafb; }
        .button { display: inline-block; padding: 12px 24px; background: #6366f1; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Réinitialisation de mot de passe</h1>
        </div>
        <div class="content">
            <p>Bonjour,</p>
            <p>Vous avez demandé à réinitialiser votre mot de passe pour votre compte <strong>{$tenantName}</strong> sur StudyMate.</p>

            <a href="{$resetUrl}" class="button">Réinitialiser mon mot de passe</a>

            <p>Ce lien est valide pendant 1 heure.</p>
            <p>Si vous n'avez pas demandé cette réinitialisation, ignorez cet email.</p>
        </div>
        <div class="footer">
            <p>StudyMate - Plateforme d'orchestration pédagogique</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render deactivation notice template
     */
    private function renderDeactivationTemplate($email, $tenantName, $reason) {
        $reasonText = $reason ? "<p><strong>Raison :</strong> {$reason}</p>" : "";

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #ef4444; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f9fafb; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Compte désactivé</h1>
        </div>
        <div class="content">
            <p>Bonjour,</p>
            <p>Votre compte pour <strong>{$tenantName}</strong> sur StudyMate a été désactivé.</p>
            {$reasonText}
            <p>Vous ne pourrez plus vous connecter avec ce compte.</p>
            <p>Pour toute question, veuillez contacter votre administrateur.</p>
        </div>
        <div class="footer">
            <p>StudyMate - Plateforme d'orchestration pédagogique</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Check if mailer is properly configured
     */
    public function isConfigured() {
        return !empty($this->fromEmail);
    }

    /**
     * Test email configuration
     */
    public function testConfiguration($testEmail) {
        $subject = "Test de configuration email - StudyMate";
        $body = "<html><body><h1>Test réussi!</h1><p>La configuration email fonctionne correctement.</p></body></html>";

        return $this->send($testEmail, $subject, $body);
    }
}
