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
5 => [
// Retention of permanently deleted messages ("second trash", administrators only).
"CREATE TABLE deleted_messages (
  id {PK},
  user_id INTEGER NULL,
  user_email VARCHAR(190) NOT NULL DEFAULT '',
  folder_name VARCHAR(255) NOT NULL DEFAULT '',
  original_folder_id INTEGER NULL,
  message_id VARCHAR(500) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  from_name VARCHAR(255) NOT NULL DEFAULT '',
  from_email VARCHAR(255) NOT NULL DEFAULT '',
  to_list {TEXT},
  date_sent INTEGER NOT NULL DEFAULT 0,
  size INTEGER NOT NULL DEFAULT 0,
  has_attachments INTEGER NOT NULL DEFAULT 0,
  is_personal INTEGER NOT NULL DEFAULT 0,
  blob_id VARCHAR(64) NOT NULL,
  reason VARCHAR(30) NOT NULL DEFAULT 'deleted',
  deleted_at INTEGER NOT NULL DEFAULT 0,
  deleted_by INTEGER NULL,
  deleted_by_name VARCHAR(190) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  restored_at INTEGER NOT NULL DEFAULT 0,
  restored_by INTEGER NULL,
  restored_to VARCHAR(190) NOT NULL DEFAULT ''
) {OPT}",
"CREATE INDEX deleted_messages_user ON deleted_messages(user_id, deleted_at)",
"CREATE INDEX deleted_messages_date ON deleted_messages(deleted_at)",
"ALTER TABLE messages ADD COLUMN trashed_from INTEGER NULL",
"ALTER TABLE messages ADD COLUMN trashed_from_name VARCHAR(255) NOT NULL DEFAULT ''",
],
6 => [
// "Webmail" mode: each user's mailbox lives at an external provider (IMAP + SMTP).
"CREATE TABLE remote_accounts (
  id {PK},
  user_id INTEGER NOT NULL,
  login VARCHAR(190) NOT NULL DEFAULT '',
  password_enc {TEXT},
  imap_host VARCHAR(190) NOT NULL DEFAULT '',
  imap_port INTEGER NOT NULL DEFAULT 0,
  imap_security VARCHAR(10) NOT NULL DEFAULT '',
  smtp_host VARCHAR(190) NOT NULL DEFAULT '',
  smtp_port INTEGER NOT NULL DEFAULT 0,
  smtp_security VARCHAR(10) NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 1,
  inbox_uid INTEGER NOT NULL DEFAULT 0,
  inbox_validity INTEGER NOT NULL DEFAULT 0,
  spam_uid INTEGER NOT NULL DEFAULT 0,
  spam_validity INTEGER NOT NULL DEFAULT 0,
  last_sync_at INTEGER NOT NULL DEFAULT 0,
  last_ok_at INTEGER NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX remote_accounts_user ON remote_accounts(user_id)",
"CREATE TABLE remote_uids (
  id {PK},
  account_id INTEGER NOT NULL,
  folder VARCHAR(10) NOT NULL DEFAULT 'inbox',
  validity INTEGER NOT NULL DEFAULT 0,
  uid INTEGER NOT NULL,
  imported_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX remote_uids_account ON remote_uids(account_id, imported_at)",
],
7 => [
// Web Push subscriptions (one per browser / installed app).
"CREATE TABLE push_subscriptions (
  id {PK},
  user_id INTEGER NOT NULL,
  endpoint {TEXT},
  endpoint_hash VARCHAR(64) NOT NULL,
  p256dh VARCHAR(200) NOT NULL,
  auth VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  failures INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  last_used_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX push_subscriptions_endpoint ON push_subscriptions(endpoint_hash)",
"CREATE INDEX push_subscriptions_user ON push_subscriptions(user_id)",
],
8 => [
// Collector mode: outgoing volume of the shared provider account (hourly limit)…
"CREATE TABLE send_log (
  id {PK},
  user_id INTEGER NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'mail',
  recipients INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX send_log_time ON send_log(created_at)",
// …and messages already handed to a user, so a copy received twice is delivered once.
"CREATE TABLE collector_seen (
  id {PK},
  msg_hash VARCHAR(64) NOT NULL,
  user_id INTEGER NOT NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX collector_seen_msg ON collector_seen(msg_hash, user_id)",
],
9 => [
// "My other mailboxes": shared mailboxes attached to a person's account.
"CREATE TABLE mailbox_links (
  id {PK},
  user_id INTEGER NOT NULL,
  mailbox_id INTEGER NOT NULL,
  method VARCHAR(10) NOT NULL DEFAULT 'admin',
  created_by INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX mailbox_links_pair ON mailbox_links(user_id, mailbox_id)",
"CREATE INDEX mailbox_links_box ON mailbox_links(mailbox_id)",
],
10 => [
// A single file shared without its folder (read access).
"CREATE TABLE fs_file_acl (
  id {PK},
  file_id INTEGER NOT NULL,
  principal_type VARCHAR(20) NOT NULL,
  principal VARCHAR(190) NOT NULL,
  level INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX fs_file_acl_unique ON fs_file_acl(file_id, principal_type, principal)",
"CREATE INDEX fs_file_acl_principal ON fs_file_acl(principal_type, principal)",
],
11 => [
// Every stored document has an expiry date; past it, the document is archived.
"ALTER TABLE fs_files ADD COLUMN expires_at INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE fs_files ADD COLUMN expiry_stage INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE fs_files ADD COLUMN archived_at INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE fs_files ADD COLUMN archived_from INTEGER NULL",
"CREATE INDEX fs_files_expiry ON fs_files(expires_at)",
// Existing documents get the default period from today, never an immediate expiry.
static function (): void {
    $days = max(1, (int) \M4W\Core\Settings::get('files.expiry_default_days', 365));
    \M4W\Core\Database::run('UPDATE fs_files SET expires_at = :t WHERE expires_at = 0', ['t' => time() + $days * 86400]);
},
],
12 => [
// Online editing through an office server (WOPI): access tokens and document locks.
"CREATE TABLE wopi_tokens (
  id {PK},
  token_hash VARCHAR(64) NOT NULL,
  file_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  can_write INTEGER NOT NULL DEFAULT 0,
  expires_at INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX wopi_tokens_hash ON wopi_tokens(token_hash)",
"CREATE TABLE wopi_locks (
  id {PK},
  file_id INTEGER NOT NULL,
  lock_id VARCHAR(1024) NOT NULL,
  expires_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX wopi_locks_file ON wopi_locks(file_id)",
],
13 => [
// Pictures of received messages, fetched once and cleaned server side (anti-tracking).
// Stored once per content (checksum), referenced by message: no reference left, picture gone.
"CREATE TABLE mail_assets (
  id {PK},
  sha256 VARCHAR(64) NOT NULL,
  blob_id VARCHAR(64) NOT NULL DEFAULT '',
  kind VARCHAR(10) NOT NULL DEFAULT 'image',
  mime VARCHAR(40) NOT NULL DEFAULT '',
  width INTEGER NOT NULL DEFAULT 0,
  height INTEGER NOT NULL DEFAULT 0,
  size INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX mail_assets_sha ON mail_assets(sha256)",
"CREATE TABLE mail_asset_refs (
  id {PK},
  message_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  ref_key VARCHAR(64) NOT NULL,
  url {TEXT},
  asset_id INTEGER NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending',
  tries INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX mail_asset_refs_key ON mail_asset_refs(message_id, ref_key)",
"CREATE INDEX mail_asset_refs_asset ON mail_asset_refs(asset_id)",
"CREATE INDEX mail_asset_refs_status ON mail_asset_refs(status, created_at)",
],
14 => [
// Verdicts on links (Google Safe Browsing / Web Risk), cached to spare the API and keep clicks fast.
"CREATE TABLE url_verdicts (
  url_hash VARCHAR(64) NOT NULL PRIMARY KEY,
  threat VARCHAR(40) NOT NULL DEFAULT '',
  checked_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX url_verdicts_checked ON url_verdicts(checked_at)",
],
15 => [
// A user's own mailbox at the provider, imposed by the administrator in any mode: mail is
// fetched from it and sent through it (with its own hourly limit, overflowing to the
// collector mailbox when allowed).
"ALTER TABLE remote_accounts ADD COLUMN forced INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE remote_accounts ADD COLUMN overflow INTEGER NOT NULL DEFAULT 1",
"ALTER TABLE remote_accounts ADD COLUMN hourly_limit INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE remote_accounts ADD COLUMN blocked_until INTEGER NOT NULL DEFAULT 0",
// Which mailbox sent (0 = the collector): each one has its own hourly counter.
"ALTER TABLE send_log ADD COLUMN account INTEGER NOT NULL DEFAULT 0",
"CREATE INDEX send_log_account ON send_log(account, created_at)",
"CREATE INDEX messages_msgid ON messages(user_id, message_id)",
],
16 => [
// History of the backups sent to the backup server.
"CREATE TABLE backup_runs (
  id {PK},
  started_at INTEGER NOT NULL DEFAULT 0,
  finished_at INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'running',
  origin VARCHAR(10) NOT NULL DEFAULT 'manual',
  file VARCHAR(190) NOT NULL DEFAULT '',
  size BIGINT NOT NULL DEFAULT 0,
  entries INTEGER NOT NULL DEFAULT 0,
  message VARCHAR(500) NOT NULL DEFAULT ''
) {OPT}",
"CREATE INDEX backup_runs_started ON backup_runs(started_at)",
],
17 => [
// Built-in spreadsheet: one working copy per file being edited, the operations everyone
// applied to it (polled by the other editors) and who is looking at which cell.
"CREATE TABLE sheet_sessions (
  file_id INTEGER NOT NULL PRIMARY KEY,
  base_version INTEGER NOT NULL DEFAULT 0,
  mode VARCHAR(10) NOT NULL DEFAULT 'edit',
  reason VARCHAR(40) NOT NULL DEFAULT '',
  dirty INTEGER NOT NULL DEFAULT 0,
  last_user INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0,
  published_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE TABLE sheet_ops (
  id {PK},
  file_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  op {TEXT},
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX sheet_ops_file ON sheet_ops(file_id, id)",
"CREATE TABLE sheet_presence (
  file_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  sheet INTEGER NOT NULL DEFAULT 0,
  cell VARCHAR(20) NOT NULL DEFAULT '',
  seen_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (file_id, user_id)
) {OPT}",
],
18 => [
// Built-in word processor: working copy per document, who holds the pen, revision followed by readers.
"CREATE TABLE doc_sessions (
  file_id INTEGER NOT NULL PRIMARY KEY,
  base_version INTEGER NOT NULL DEFAULT 0,
  mode VARCHAR(10) NOT NULL DEFAULT 'edit',
  reason VARCHAR(190) NOT NULL DEFAULT '',
  holder INTEGER NULL,
  holder_seen INTEGER NOT NULL DEFAULT 0,
  rev INTEGER NOT NULL DEFAULT 1,
  dirty INTEGER NOT NULL DEFAULT 0,
  last_user INTEGER NULL,
  updated_at INTEGER NOT NULL DEFAULT 0,
  published_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
],
19 => [
// Calendars: personal, shared (members with read or write access) and rooms. Events are kept
// as iCalendar objects (the CalDAV source of truth), with a few columns for range queries.
"CREATE TABLE calendars (
  id {PK},
  owner_id INTEGER NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'personal',
  uri VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  color VARCHAR(10) NOT NULL DEFAULT '#7c3aed',
  description VARCHAR(500) NOT NULL DEFAULT '',
  ctag INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER NULL,
  created_at INTEGER NOT NULL DEFAULT 0,
  deleted_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX calendars_owner ON calendars(owner_id, deleted_at)",
"CREATE TABLE calendar_acl (
  calendar_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  level VARCHAR(10) NOT NULL DEFAULT 'read',
  PRIMARY KEY (calendar_id, user_id)
) {OPT}",
"CREATE TABLE cal_objects (
  id {PK},
  calendar_id INTEGER NOT NULL,
  uri VARCHAR(190) NOT NULL,
  uid VARCHAR(255) NOT NULL DEFAULT '',
  etag VARCHAR(40) NOT NULL DEFAULT '',
  size INTEGER NOT NULL DEFAULT 0,
  data {TEXT},
  component VARCHAR(10) NOT NULL DEFAULT 'VEVENT',
  summary VARCHAR(255) NOT NULL DEFAULT '',
  first_occurrence INTEGER NOT NULL DEFAULT 0,
  last_occurrence INTEGER NOT NULL DEFAULT 0,
  next_alarm INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX cal_objects_uri ON cal_objects(calendar_id, uri)",
"CREATE INDEX cal_objects_uid ON cal_objects(calendar_id, uid)",
"CREATE INDEX cal_objects_range ON cal_objects(calendar_id, first_occurrence, last_occurrence)",
"CREATE INDEX cal_objects_alarm ON cal_objects(next_alarm)",
// Passwords for phones and desktop apps (CalDAV / CardDAV): never the account password.
"CREATE TABLE app_passwords (
  id {PK},
  user_id INTEGER NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  hash VARCHAR(255) NOT NULL,
  created_at INTEGER NOT NULL DEFAULT 0,
  last_used_at INTEGER NOT NULL DEFAULT 0,
  last_ip VARCHAR(64) NOT NULL DEFAULT ''
) {OPT}",
"CREATE INDEX app_passwords_user ON app_passwords(user_id)",
// Contacts synchronised with phones (CardDAV): the vCard keeps what the web form does not show.
"ALTER TABLE contacts ADD COLUMN uri VARCHAR(190) NOT NULL DEFAULT ''",
"ALTER TABLE contacts ADD COLUMN etag VARCHAR(40) NOT NULL DEFAULT ''",
"ALTER TABLE contacts ADD COLUMN vcard {TEXT}",
"ALTER TABLE contacts ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0",
],
20 => [
// Compromised accounts: where people usually sign in from, what they send, what looked wrong.
"CREATE TABLE known_logins (
  id {PK},
  user_id INTEGER NOT NULL,
  net VARCHAR(64) NOT NULL DEFAULT '',
  agent VARCHAR(120) NOT NULL DEFAULT '',
  first_at INTEGER NOT NULL DEFAULT 0,
  last_at INTEGER NOT NULL DEFAULT 0,
  last_ip VARCHAR(64) NOT NULL DEFAULT ''
) {OPT}",
"CREATE INDEX known_logins_user ON known_logins(user_id, net)",
"CREATE TABLE guard_sends (
  id {PK},
  user_id INTEGER NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'mail',
  recipients INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX guard_sends_user ON guard_sends(user_id, created_at)",
"CREATE TABLE security_alerts (
  id {PK},
  user_id INTEGER NULL,
  kind VARCHAR(30) NOT NULL,
  severity VARCHAR(10) NOT NULL DEFAULT 'info',
  details {TEXT},
  ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  resolved_at INTEGER NOT NULL DEFAULT 0,
  resolved_by INTEGER NULL
) {OPT}",
"CREATE INDEX security_alerts_time ON security_alerts(resolved_at, created_at)",
"ALTER TABLE users ADD COLUMN send_blocked_until INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE users ADD COLUMN send_block_reason VARCHAR(255) NOT NULL DEFAULT ''",
],
21 => [
// Antispam that learns from what people mark as junk (or not): word statistics only, hashed.
"CREATE TABLE bayes_tokens (
  token VARCHAR(16) NOT NULL PRIMARY KEY,
  spam INTEGER NOT NULL DEFAULT 0,
  ham INTEGER NOT NULL DEFAULT 0,
  last_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"ALTER TABLE messages ADD COLUMN bayes INTEGER NOT NULL DEFAULT 0",
// Senders reported for phishing by someone of the organisation.
"CREATE TABLE reported_senders (
  address VARCHAR(190) NOT NULL PRIMARY KEY,
  reports INTEGER NOT NULL DEFAULT 1,
  first_at INTEGER NOT NULL DEFAULT 0,
  last_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX messages_msgid_all ON messages(message_id)",
],
22 => [
// DMARC aggregate reports (rua) sent by Gmail, Microsoft, Yahoo… about mail using our domains.
"CREATE TABLE dmarc_reports (
  id {PK},
  org VARCHAR(190) NOT NULL DEFAULT '',
  report_id VARCHAR(190) NOT NULL DEFAULT '',
  domain VARCHAR(190) NOT NULL DEFAULT '',
  policy VARCHAR(20) NOT NULL DEFAULT '',
  date_begin INTEGER NOT NULL DEFAULT 0,
  date_end INTEGER NOT NULL DEFAULT 0,
  messages INTEGER NOT NULL DEFAULT 0,
  failed INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX dmarc_reports_uid ON dmarc_reports(org, report_id)",
"CREATE INDEX dmarc_reports_end ON dmarc_reports(date_end)",
"CREATE TABLE dmarc_records (
  id {PK},
  report_id INTEGER NOT NULL,
  source_ip VARCHAR(64) NOT NULL DEFAULT '',
  messages INTEGER NOT NULL DEFAULT 0,
  disposition VARCHAR(20) NOT NULL DEFAULT '',
  dkim VARCHAR(10) NOT NULL DEFAULT '',
  spf VARCHAR(10) NOT NULL DEFAULT '',
  header_from VARCHAR(190) NOT NULL DEFAULT ''
) {OPT}",
"CREATE INDEX dmarc_records_report ON dmarc_records(report_id)",
],
23 => [
// Rooms: weekly opening hours, approval by managers, capacity (JSON on the room calendar).
"ALTER TABLE calendars ADD COLUMN room_config {TEXT}",
// Room bookings made from events: the backlog of the room managers.
"CREATE TABLE room_bookings (
  id {PK},
  room_id INTEGER NOT NULL,
  requester_id INTEGER NOT NULL,
  calendar_id INTEGER NOT NULL,
  event_uri VARCHAR(190) NOT NULL DEFAULT '',
  uid VARCHAR(255) NOT NULL DEFAULT '',
  title VARCHAR(255) NOT NULL DEFAULT '',
  start_at INTEGER NOT NULL DEFAULT 0,
  end_at INTEGER NOT NULL DEFAULT 0,
  recurring INTEGER NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  decided_by INTEGER NULL,
  decided_at INTEGER NOT NULL DEFAULT 0,
  comment VARCHAR(500) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX room_bookings_room ON room_bookings(room_id, status)",
"CREATE INDEX room_bookings_uid ON room_bookings(uid)",
],
24 => [
// An own mailbox (imposed by the administrator) has its own rules: never emptied unless
// chosen, sent mail copied to its Sent folder, and mail can be copied back into it.
"ALTER TABLE remote_accounts ADD COLUMN leave_days INTEGER NOT NULL DEFAULT -1",
"ALTER TABLE remote_accounts ADD COLUMN save_sent INTEGER NOT NULL DEFAULT 1",
"ALTER TABLE remote_accounts ADD COLUMN sent_folder VARCHAR(190) NOT NULL DEFAULT ''",
"ALTER TABLE remote_accounts ADD COLUMN restore_state {TEXT}",
],
25 => [
// Messages pinned at the top of their folder.
"ALTER TABLE messages ADD COLUMN is_pinned INTEGER NOT NULL DEFAULT 0",
],
26 => [
// Senders a person took out of Junk ("not junk"): their mail no longer goes there.
"CREATE TABLE spam_trusted (
  id {PK},
  user_id INTEGER NOT NULL,
  address VARCHAR(255) NOT NULL,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX spam_trusted_ua ON spam_trusted(user_id, address)",
],
27 => [
// Two-way synchronisation with the provider mailbox: what each collected message is, and the
// read / flagged state both sides last agreed on.
"ALTER TABLE remote_uids ADD COLUMN msgid VARCHAR(255) NOT NULL DEFAULT ''",
"ALTER TABLE remote_uids ADD COLUMN seen INTEGER NOT NULL DEFAULT -1",
"ALTER TABLE remote_uids ADD COLUMN flagged INTEGER NOT NULL DEFAULT -1",
"CREATE INDEX remote_uids_msgid ON remote_uids(account_id, msgid)",
"ALTER TABLE remote_accounts ADD COLUMN two_way INTEGER NOT NULL DEFAULT 1",
"ALTER TABLE remote_accounts ADD COLUMN trash_folder VARCHAR(190) NOT NULL DEFAULT ''",
"ALTER TABLE remote_accounts ADD COLUMN archive_folder VARCHAR(190) NOT NULL DEFAULT ''",
"ALTER TABLE remote_accounts ADD COLUMN empty_streak INTEGER NOT NULL DEFAULT 0",
],
28 => [
// Mail delivered here directly (between two mailboxes of the server) for someone whose mailbox
// lives at the provider: a copy waits to be placed in the provider's inbox at the next sync.
"CREATE TABLE provider_copies (
  id {PK},
  user_id INTEGER NOT NULL,
  message_id INTEGER NOT NULL,
  created_at INTEGER NOT NULL DEFAULT 0,
  tries INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX provider_copies_user ON provider_copies(user_id)",
],
29 => [
// BIMI: the sender's logo, kept per domain (cleaned SVG, mark certificate checked or not), and
// the domain whose logo goes with each message ('' = initials).
"ALTER TABLE messages ADD COLUMN bimi VARCHAR(190) NOT NULL DEFAULT ''",
"CREATE TABLE bimi_logos (
  id {PK},
  domain VARCHAR(190) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'none',
  file VARCHAR(80) NOT NULL DEFAULT '',
  vmc INTEGER NOT NULL DEFAULT 0,
  error VARCHAR(255) NOT NULL DEFAULT '',
  checked_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX bimi_logos_domain ON bimi_logos(domain)",
],
30 => [
// PHP sessions in the database (shared by several web servers): the id is stored hashed.
"CREATE TABLE php_sessions (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  data {TEXT},
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX php_sessions_updated ON php_sessions(updated_at)",
],
31 => [
// One file for all the recipients of a delivery: each message keeps its own header lines here,
// and a file is deleted only when no message points to it any more.
"ALTER TABLE messages ADD COLUMN raw_prefix {TEXT}",
static function (): void {
    try {
        \M4W\Core\Database::pdo()->exec('CREATE INDEX messages_storage ON messages(storage_path)');
    } catch (\PDOException $e) {
        if (\M4W\Core\Database::driver() !== 'mysql') {
            throw $e;
        }
        // Older MySQL (767-byte index keys): the beginning of the path is enough.
        \M4W\Core\Database::pdo()->exec('CREATE INDEX messages_storage ON messages(storage_path(191))');
    }
},
],
32 => [
// Files: the same content is stored once (by SHA-256) and removed when nothing uses it.
"ALTER TABLE fs_blobs ADD COLUMN last_used_at INTEGER NOT NULL DEFAULT 0",
"CREATE INDEX fs_blobs_sha ON fs_blobs(sha256)",
"CREATE INDEX fs_files_blob ON fs_files(blob_id)",
"CREATE INDEX fs_versions_blob ON fs_versions(blob_id)",
],
33 => [
// Outgoing queue: when a message was taken, and how many times its sending stopped the worker
// (a message that keeps doing it is set aside, "parked", instead of blocking the queue).
"ALTER TABLE mail_queue ADD COLUMN claimed_at INTEGER NOT NULL DEFAULT 0",
"ALTER TABLE mail_queue ADD COLUMN crashes INTEGER NOT NULL DEFAULT 0",
],34 => [
// Offline mode: one key per person and device (the device keeps a copy only while signed in:
// its stored mail and pending work are unreadable once the session ends, until the same person
// signs in again), and the actions it replayed (each applied once, in order, never too old).
"CREATE TABLE offline_devices (
  id {PK},
  user_id INTEGER NOT NULL,
  device VARCHAR(64) NOT NULL,
  key_enc VARCHAR(255) NOT NULL,
  session_id INTEGER NOT NULL DEFAULT 0,
  last_seq INTEGER NOT NULL DEFAULT 0,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0,
  last_seen_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX offline_devices_user ON offline_devices(user_id, device)",
"CREATE INDEX offline_devices_session ON offline_devices(session_id)",
"CREATE TABLE offline_actions (
  id {PK},
  device_id INTEGER NOT NULL,
  action_id VARCHAR(64) NOT NULL,
  seq INTEGER NOT NULL DEFAULT 0,
  path VARCHAR(100) NOT NULL DEFAULT '',
  status INTEGER NOT NULL DEFAULT 0,
  response {TEXT},
  created_at INTEGER NOT NULL DEFAULT 0,
  applied_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX offline_actions_id ON offline_actions(device_id, action_id)",
],35 => [
// Reminders "3 unread messages": when the last one was sent to a person.
"CREATE TABLE notify_reminders (
  user_id INTEGER NOT NULL PRIMARY KEY,
  sent_at INTEGER NOT NULL DEFAULT 0,
  unread INTEGER NOT NULL DEFAULT 0
) {OPT}",
// External account added by a person: how often it is checked (minutes; 0 = the administrator's setting).
"ALTER TABLE fetch_accounts ADD COLUMN interval_min INTEGER NOT NULL DEFAULT 0",
],36 => [
// A person's own notification sounds, offered for every kind of alert.
"CREATE TABLE user_sounds (
  id {PK},
  user_id INTEGER NOT NULL,
  label VARCHAR(100) NOT NULL DEFAULT '',
  file VARCHAR(100) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX user_sounds_user ON user_sounds(user_id)",
// The sound sent with the previous version (one per kind of alert) joins the person's sounds.
static function (): void {
    $DB = \M4W\Core\Database::class;
    foreach ($DB::all("SELECT id, prefs FROM users WHERE prefs LIKE '%sound_file_%'") as $u) {
        $p = json_decode((string) $u['prefs'], true) ?: [];
        foreach (['new', 'reminder'] as $k) {
            $file = (string) ($p['sound_file_' . $k] ?? '');
            $chosen = ($p['sound_' . $k] ?? '') === 'custom';
            if ($file !== '' && preg_match('/^\d+-(new|reminder)-[0-9a-f]{12}\.(mp3|ogg|wav)$/', $file)) {
                $id = $DB::insert('user_sounds', ['user_id' => $u['id'], 'label' => $k === 'new' ? 'Mon son' : 'Mon son (relance)', 'file' => $file, 'created_at' => time()]);
                if ($chosen) {
                    $p['sound_' . $k] = 'own:' . $id;
                }
            } elseif ($chosen) {
                $p['sound_' . $k] = $k === 'new' ? 'ding' : 'chime';
            }
            unset($p['sound_file_' . $k]);
        }
        $DB::update('users', ['prefs' => json_encode($p)], 'id = :id', ['id' => $u['id']]);
    }
},
],
37 => [
// The app open on a device (its checks, every 10 to 20 s): no system notification there, the app
// announces new mail itself, with the person's sound, once its list is up to date.
"ALTER TABLE push_subscriptions ADD COLUMN active_at INTEGER NOT NULL DEFAULT 0",
// Held back because the app was open; sent if no check comes back from that device (closed meanwhile).
"CREATE TABLE push_pending (
  id {PK},
  subscription_id INTEGER NOT NULL,
  payload {TEXT},
  ttl INTEGER NOT NULL DEFAULT 86400,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX push_pending_sub ON push_pending(subscription_id)",
],
38 => [
// One's own folders at the provider too (ProviderFolders): which local folder is which path there.
"CREATE TABLE remote_folders (
  id {PK},
  account_id INTEGER NOT NULL,
  folder_id INTEGER NOT NULL,
  path VARCHAR(255) NOT NULL DEFAULT '',
  validity INTEGER NOT NULL DEFAULT 0,
  last_uid INTEGER NOT NULL DEFAULT 0,
  history_uid INTEGER NOT NULL DEFAULT 0,
  missing INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE UNIQUE INDEX remote_folders_folder ON remote_folders(account_id, folder_id)",
"ALTER TABLE remote_accounts ADD COLUMN sync_folders INTEGER NOT NULL DEFAULT 1",
],
39 => [
// How a copy for the provider mailbox came here (the collector: given time to arrive there by itself).
"ALTER TABLE provider_copies ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT ''",
],
40 => [
// Every message this webmail put into a provider mailbox (copy, restore, one's own folders): never twice.
"CREATE TABLE provider_pushed (
  id {PK},
  account_id INTEGER NOT NULL,
  local_id INTEGER NOT NULL DEFAULT 0,
  msgid VARCHAR(255) NOT NULL DEFAULT '',
  fkey VARCHAR(40) NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX provider_pushed_msgid ON provider_pushed(account_id, msgid)",
"CREATE INDEX provider_pushed_fkey ON provider_pushed(account_id, fkey)",
"CREATE INDEX provider_pushed_local ON provider_pushed(account_id, local_id)",
],
41 => [
// What became of each message sent, recipient by recipient (DeliveryReports).
"CREATE TABLE delivery_status (
  id {PK},
  user_id INTEGER NOT NULL,
  message_id VARCHAR(255) NOT NULL DEFAULT '',
  recipient VARCHAR(255) NOT NULL DEFAULT '',
  state VARCHAR(20) NOT NULL DEFAULT 'handed',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  dsn INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL DEFAULT 0
) {OPT}",
"CREATE INDEX delivery_status_msg ON delivery_status(user_id, message_id)",
],
42 => [
// DKIM selectors the receivers saw signing (DMARC reports), and the sender check of messages
// collected from a provider, worked out once when first read.
"ALTER TABLE dmarc_records ADD COLUMN dkim_selector VARCHAR(64) NOT NULL DEFAULT ''",
"ALTER TABLE messages ADD COLUMN auth_cache VARCHAR(255) NOT NULL DEFAULT ''",
],
43 => [
// Signatures made with the guided editor: logo, position, separator, networks, notice (JSON).
"ALTER TABLE signature_templates ADD COLUMN options {TEXT}",
// The default template of older versions, never changed: it becomes a guided one (same look,
// and the new options apply to it). One edited by hand stays as it was written.
static function (): void {
    $norm = static fn(string $h) => (string) preg_replace('/\s+/', ' ', trim($h));
    $legacy = $norm(\M4W\Service\Signatures::legacyDefault());
    foreach (\M4W\Core\Database::all('SELECT id, html FROM signature_templates') as $t) {
        if ($norm((string) $t['html']) === $legacy) {
            \M4W\Core\Database::update('signature_templates', ['html' => '', 'options' => json_encode(\M4W\Service\Signatures::OPTIONS + ['website_below' => 1])], 'id = :id', ['id' => $t['id']]);
        }
    }
},
],
44 => [
// Roles: who installed the application (the first administrator) becomes the super administrator,
// with every right; the other administrators keep the customer's administration (see is_admin()).
static function (): void {
    $first = \M4W\Core\Database::value("SELECT MIN(id) FROM users WHERE role = 'admin'");
    if ($first && !\M4W\Core\Database::value("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")) {
        \M4W\Core\Database::update('users', ['role' => 'superadmin'], 'id = :id', ['id' => (int) $first]);
    }
},
],
45 => [
// "Stay signed in on this device": a session chosen at sign-in, with its own longer limits (a
// phone locked often cannot keep an ordinary session from going idle).
"ALTER TABLE user_sessions ADD COLUMN trusted INTEGER NOT NULL DEFAULT 0",
],
];
