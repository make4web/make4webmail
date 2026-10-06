<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Response;
use M4W\Core\Settings;

/** In-app user guide. The administration chapters are rendered for administrators only. */
final class HelpController extends Controller
{
    public const USER_TOC = [
        'start' => ['rocket-takeoff', 'Premiers pas'],
        'read' => ['envelope-open', 'Lire et organiser'],
        'search' => ['search', 'Rechercher'],
        'compose' => ['pencil-square', 'Rédiger et envoyer'],
        'rules' => ['funnel', 'Règles de tri'],
        'vacation' => ['airplane', 'Réponse automatique'],
        'forwarding' => ['forward', 'Transfert'],
        'signature' => ['pen', 'Signature'],
        'contacts' => ['people', 'Contacts et annuaire'],
        'files' => ['folder2-open', 'Fichiers partagés'],
        'security' => ['shield-lock', 'Sécurité du compte'],
        'privacy' => ['lock', 'Messages personnels et accès'],
        'delegated' => ['person-badge', 'Boîtes déléguées'],
        'shortcuts' => ['keyboard', 'Raccourcis clavier'],
        'faq' => ['question-circle', 'Questions fréquentes'],
    ];

    public const ADMIN_TOC = [
        'a-overview' => ['speedometer2', 'Vue d\'ensemble'],
        'a-users' => ['person-gear', 'Utilisateurs'],
        'a-domains' => ['globe2', 'Domaines et alias'],
        'a-signatures' => ['vector-pen', 'Modèles de signature'],
        'a-branding' => ['palette', 'Personnalisation'],
        'a-files' => ['folder2-open', 'Espace fichiers'],
        'a-delegation' => ['person-badge', 'Délégation des boîtes'],
        'a-deleted' => ['trash3', 'Messages supprimés'],
        'a-mail' => ['hdd-network', 'Serveur de messagerie'],
        'a-security' => ['shield-check', 'Politique de sécurité'],
        'a-logs' => ['journal-text', 'Journaux et suivi'],
        'a-ops' => ['tools', 'Exploitation'],
    ];

    public function index(): Response
    {
        $u = $this->actor();
        $isAdmin = ($u['role'] ?? '') === 'admin';
        return $this->view('help/index', [
            'isAdmin' => $isAdmin,
            'toc' => ['user' => self::USER_TOC, 'admin' => $isAdmin ? self::ADMIN_TOC : []],
            'brand' => (string) Settings::get('brand.name', 'Make4Web Mail'),
            'cfg' => [
                'undo' => (int) Settings::get('features.undo_send_seconds', 5),
                'attach' => (int) Settings::get('security.max_attachment_mb', 25),
                'file' => (int) Settings::get('files.max_file_mb', 200),
                'files_trash' => (int) Settings::get('files.trash_days', 30),
                'versions' => (int) Settings::get('files.versions', 10),
                'retention' => (int) Settings::get('retention.days', 365),
                'retention_on' => (int) Settings::get('retention.enabled', 1) === 1,
                'deleg_admins' => (int) Settings::get('delegation.admins', 1) === 1,
                'deleg_notify' => (int) Settings::get('delegation.notify_owner', 1) === 1,
                'hide_personal' => (int) Settings::get('delegation.hide_personal', 1) === 1,
                'user_sig' => (int) Settings::get('features.user_signature', 1) === 1,
                'fetch' => (int) Settings::get('features.fetch_accounts', 1) === 1,
                'ext_forward' => (int) Settings::get('security.allow_external_forward', 1) === 1,
                'user_spaces' => (int) Settings::get('files.users_create_spaces', 0) === 1,
                'block_images' => (int) Settings::get('security.block_remote_images', 1) === 1,
                'max_attempts' => (int) Settings::get('security.max_attempts', 5),
                'lockout' => (int) Settings::get('security.lockout_minutes', 15),
            ],
        ]);
    }
}
