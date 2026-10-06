<?php
/**
 * Portable schema. Tokens are expanded per driver by Migrator:
 *   {PK}   auto-increment primary key
 *   {TEXT} large text column
 *   {OPT}  table options
 *   {BLOB} large binary column
 * All timestamps are unix epoch integers.
 */
return [
1 => [
"CREATE TABLE settings (
  name VARCHAR(100) NOT NULL PRIMARY KEY,
  value {TEXT}
) {OPT}",

"CREATE TABLE domains (
  id {PK},
  name VARCHAR(190) NOT NULL UNIQUE,
  active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE signature_templates (
  id {PK},
  name VARCHAR(190) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  html {TEXT},
  is_default INTEGER NOT NULL DEFAULT 0,
  apply_on_reply INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE users (
  id {PK},
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(190) NOT NULL DEFAULT '',
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  job_title VARCHAR(190) NOT NULL DEFAULT '',
  department VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(60) NOT NULL DEFAULT '',
  mobile VARCHAR(60) NOT NULL DEFAULT '',
  role VARCHAR(20) NOT NULL DEFAULT 'user',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  quota_mb INTEGER NOT NULL DEFAULT 2048,
  used_bytes INTEGER NOT NULL DEFAULT 0,
  signature_template_id INTEGER NULL,
  personal_signature {TEXT},
  language VARCHAR(10) NOT NULL DEFAULT 'fr',
  prefs {TEXT},
  totp_secret {TEXT},
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  must_change_password INTEGER NOT NULL DEFAULT 0,
  password_changed_at INTEGER NOT NULL DEFAULT 0,
  last_login_at INTEGER NOT NULL DEFAULT 0,
  last_login_ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE aliases (
  id {PK},
  address VARCHAR(190) NOT NULL UNIQUE,
  user_id INTEGER NOT NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE folders (
  id {PK},
  user_id INTEGER NOT NULL,
  parent_id INTEGER NULL,
  name VARCHAR(190) NOT NULL,
  role VARCHAR(20) NULL,
  color VARCHAR(20) NOT NULL DEFAULT '',
  sort INTEGER NOT NULL DEFAULT 100,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX folders_user ON folders(user_id)",

"CREATE TABLE messages (
  id {PK},
  user_id INTEGER NOT NULL,
  folder_id INTEGER NOT NULL,
  message_id VARCHAR(255) NOT NULL DEFAULT '',
  in_reply_to VARCHAR(255) NOT NULL DEFAULT '',
  thread_key VARCHAR(64) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  from_name VARCHAR(255) NOT NULL DEFAULT '',
  from_email VARCHAR(255) NOT NULL DEFAULT '',
  to_list {TEXT},
  cc_list {TEXT},
  bcc_list {TEXT},
  reply_to VARCHAR(255) NOT NULL DEFAULT '',
  date_sent INTEGER NOT NULL DEFAULT 0,
  date_received INTEGER NOT NULL DEFAULT 0,
  size INTEGER NOT NULL DEFAULT 0,
  snippet VARCHAR(300) NOT NULL DEFAULT '',
  body_text {TEXT},
  attachments {TEXT},
  has_attachments INTEGER NOT NULL DEFAULT 0,
  is_read INTEGER NOT NULL DEFAULT 0,
  is_flagged INTEGER NOT NULL DEFAULT 0,
  is_answered INTEGER NOT NULL DEFAULT 0,
  is_forwarded INTEGER NOT NULL DEFAULT 0,
  is_draft INTEGER NOT NULL DEFAULT 0,
  priority INTEGER NOT NULL DEFAULT 3,
  labels VARCHAR(255) NOT NULL DEFAULT '',
  storage_path VARCHAR(255) NOT NULL DEFAULT '',
  draft_meta {TEXT},
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX messages_folder ON messages(user_id, folder_id, date_received)",
"CREATE INDEX messages_thread ON messages(user_id, thread_key)",
"CREATE INDEX messages_unread ON messages(folder_id, is_read)",

"CREATE TABLE contacts (
  id {PK},
  user_id INTEGER NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(60) NOT NULL DEFAULT '',
  company VARCHAR(190) NOT NULL DEFAULT '',
  notes {TEXT},
  is_favorite INTEGER NOT NULL DEFAULT 0,
  auto_collected INTEGER NOT NULL DEFAULT 0,
  use_count INTEGER NOT NULL DEFAULT 0,
  last_used_at INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX contacts_user ON contacts(user_id, email)",

"CREATE TABLE rules (
  id {PK},
  user_id INTEGER NOT NULL,
  name VARCHAR(190) NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 100,
  match_type VARCHAR(10) NOT NULL DEFAULT 'all',
  conditions {TEXT},
  actions {TEXT},
  stop_processing INTEGER NOT NULL DEFAULT 0,
  hits INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX rules_user ON rules(user_id, sort)",

"CREATE TABLE vacations (
  user_id INTEGER NOT NULL PRIMARY KEY,
  enabled INTEGER NOT NULL DEFAULT 0,
  start_at INTEGER NOT NULL DEFAULT 0,
  end_at INTEGER NOT NULL DEFAULT 0,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  body_html {TEXT},
  interval_days INTEGER NOT NULL DEFAULT 4,
  only_contacts INTEGER NOT NULL DEFAULT 0,
  internal_only INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE vacation_log (
  id {PK},
  user_id INTEGER NOT NULL,
  sender VARCHAR(190) NOT NULL,
  sent_at INTEGER NOT NULL
) {OPT}",
"CREATE INDEX vacation_log_user ON vacation_log(user_id, sender)",

"CREATE TABLE forwardings (
  user_id INTEGER NOT NULL PRIMARY KEY,
  enabled INTEGER NOT NULL DEFAULT 0,
  addresses {TEXT},
  keep_copy INTEGER NOT NULL DEFAULT 1,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE fetch_accounts (
  id {PK},
  user_id INTEGER NOT NULL,
  label VARCHAR(190) NOT NULL DEFAULT '',
  protocol VARCHAR(10) NOT NULL DEFAULT 'imap',
  host VARCHAR(190) NOT NULL,
  port INTEGER NOT NULL DEFAULT 993,
  security VARCHAR(10) NOT NULL DEFAULT 'ssl',
  username VARCHAR(190) NOT NULL,
  password_enc {TEXT},
  remote_folder VARCHAR(190) NOT NULL DEFAULT 'INBOX',
  delete_remote INTEGER NOT NULL DEFAULT 0,
  apply_rules INTEGER NOT NULL DEFAULT 1,
  enabled INTEGER NOT NULL DEFAULT 1,
  last_uid INTEGER NOT NULL DEFAULT 0,
  uid_validity INTEGER NOT NULL DEFAULT 0,
  last_run_at INTEGER NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",

"CREATE TABLE user_sessions (
  id {PK},
  user_id INTEGER NOT NULL,
  token_hash VARCHAR(128) NOT NULL UNIQUE,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  last_seen_at INTEGER NOT NULL DEFAULT 0,
  revoked INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX user_sessions_user ON user_sessions(user_id)",

"CREATE TABLE login_attempts (
  id {PK},
  ip VARCHAR(64) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  success INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL
) {OPT}",
"CREATE INDEX login_attempts_ip ON login_attempts(ip, created_at)",
"CREATE INDEX login_attempts_email ON login_attempts(email, created_at)",

"CREATE TABLE audit_log (
  id {PK},
  user_id INTEGER NULL,
  action VARCHAR(100) NOT NULL,
  target VARCHAR(255) NOT NULL DEFAULT '',
  details {TEXT},
  ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL
) {OPT}",
"CREATE INDEX audit_log_created ON audit_log(created_at)",

"CREATE TABLE mail_queue (
  id {PK},
  user_id INTEGER NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'mail',
  envelope_from VARCHAR(255) NOT NULL DEFAULT '',
  recipients {TEXT},
  raw_path VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts INTEGER NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  next_attempt_at INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  sent_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX mail_queue_status ON mail_queue(status, next_attempt_at)",

"CREATE TABLE mail_log (
  id {PK},
  direction VARCHAR(10) NOT NULL,
  user_id INTEGER NULL,
  sender VARCHAR(255) NOT NULL DEFAULT '',
  recipients {TEXT},
  subject VARCHAR(500) NOT NULL DEFAULT '',
  size INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'ok',
  info VARCHAR(500) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL
) {OPT}",
"CREATE INDEX mail_log_created ON mail_log(created_at)",

"CREATE TABLE uploads (
  id {PK},
  user_id INTEGER NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  filename VARCHAR(255) NOT NULL,
  mime VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
  size INTEGER NOT NULL DEFAULT 0,
  path VARCHAR(255) NOT NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
],
2 => [
"ALTER TABLE messages ADD COLUMN scheduled_at INTEGER NOT NULL DEFAULT 0",
"CREATE INDEX messages_scheduled ON messages(scheduled_at)",
],
3 => [
// Shared file space. Content lives in a pluggable blob store (database by default).
"CREATE TABLE fs_blobs (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  size INTEGER NOT NULL DEFAULT 0,
  sha256 VARCHAR(64) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE TABLE fs_blob_chunks (
  blob_id VARCHAR(64) NOT NULL,
  seq INTEGER NOT NULL,
  data {BLOB},
  PRIMARY KEY (blob_id, seq)
) {OPT}",
"CREATE TABLE fs_folders (
  id {PK},
  parent_id INTEGER NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'folder',
  owner_id INTEGER NULL,
  name VARCHAR(255) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0,
  deleted_at INTEGER NOT NULL DEFAULT 0,
  deleted_by INTEGER NULL
) {OPT}",
"CREATE INDEX fs_folders_parent ON fs_folders(parent_id)",
"CREATE INDEX fs_folders_kind ON fs_folders(kind, owner_id)",
"CREATE TABLE fs_files (
  id {PK},
  folder_id INTEGER NOT NULL,
  name VARCHAR(255) NOT NULL,
  mime VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
  size INTEGER NOT NULL DEFAULT 0,
  blob_id VARCHAR(64) NOT NULL,
  sha256 VARCHAR(64) NOT NULL DEFAULT '',
  version INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  updated_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0,
  deleted_at INTEGER NOT NULL DEFAULT 0,
  deleted_by INTEGER NULL
) {OPT}",
"CREATE INDEX fs_files_folder ON fs_files(folder_id)",
"CREATE INDEX fs_files_deleted ON fs_files(deleted_at)",
"CREATE TABLE fs_versions (
  id {PK},
  file_id INTEGER NOT NULL,
  version INTEGER NOT NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  mime VARCHAR(190) NOT NULL DEFAULT 'application/octet-stream',
  size INTEGER NOT NULL DEFAULT 0,
  blob_id VARCHAR(64) NOT NULL,
  sha256 VARCHAR(64) NOT NULL DEFAULT '',
  created_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX fs_versions_file ON fs_versions(file_id)",
"CREATE TABLE fs_acl (
  id {PK},
  folder_id INTEGER NOT NULL,
  principal_type VARCHAR(20) NOT NULL,
  principal VARCHAR(190) NOT NULL,
  level INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX fs_acl_unique ON fs_acl(folder_id, principal_type, principal)",
"CREATE INDEX fs_acl_principal ON fs_acl(principal_type, principal)",
],
4 => [
// Mailbox delegation: explicit delegates and an access journal shown to the owner.
"CREATE TABLE mailbox_delegates (
  id {PK},
  owner_id INTEGER NOT NULL,
  delegate_id INTEGER NOT NULL,
  created_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX mailbox_delegates_pair ON mailbox_delegates(owner_id, delegate_id)",
"CREATE INDEX mailbox_delegates_delegate ON mailbox_delegates(delegate_id)",
"CREATE TABLE delegation_log (
  id {PK},
  owner_id INTEGER NOT NULL,
  actor_id INTEGER NOT NULL,
  actor_name VARCHAR(190) NOT NULL DEFAULT '',
  via VARCHAR(20) NOT NULL DEFAULT 'admin',
  reason VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  sent INTEGER NOT NULL DEFAULT 0,
  opened_at INTEGER NOT NULL DEFAULT 0,
  last_seen_at INTEGER NOT NULL DEFAULT 0,
  closed_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX delegation_log_owner ON delegation_log(owner_id, opened_at)",
"ALTER TABLE messages ADD COLUMN is_personal INTEGER NOT NULL DEFAULT 0",
// Back-fill the personal marker of existing messages (PHP step, see Migrator).
static function (): void {
    foreach (\M4W\Core\Database::all('SELECT id, subject FROM messages') as $m) {
        if (\M4W\Service\Delegation::isPersonalSubject((string) $m['subject'])) {
            \M4W\Core\Database::run('UPDATE messages SET is_personal = 1 WHERE id = :id', ['id' => $m['id']]);
        }
    }
},
],
];
