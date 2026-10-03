<?php
declare(strict_types=1);

namespace M4W\Controller;

use M4W\Core\Response;
use M4W\Service\Contacts;

final class ContactsController extends Controller
{
    public function index(): Response
    {
        $q = (string) ($this->req->query['q'] ?? '');
        $tab = (string) ($this->req->query['tab'] ?? 'mine');
        $contacts = Contacts::list($this->uid(), $q);
        $directory = \M4W\Core\Database::all("SELECT id, email, display_name, first_name, last_name, job_title, department, phone, mobile FROM users WHERE status = 'active' ORDER BY display_name, email");
        return $this->view('mail/contacts', ['contacts' => $contacts, 'q' => $q, 'tab' => $tab, 'directory' => $directory]);
    }

    public function save(): Response
    {
        try {
            Contacts::save($this->uid(), $this->req->post, $this->req->int('id') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->back('/contacts', 'danger', $e->getMessage());
        }
        return $this->back('/contacts', 'success', t('contacts.saved'));
    }

    public function delete(): Response
    {
        Contacts::delete($this->uid(), $this->req->arr('ids') ?: [$this->req->int('id')]);
        return $this->back('/contacts', 'success', t('contacts.deleted'));
    }

    public function import(): Response
    {
        $f = $this->req->files['file'] ?? null;
        if (!is_array($f) || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5_000_000) {
            return $this->back('/contacts', 'danger', t('upload.failed'));
        }
        $n = Contacts::import($this->uid(), (string) file_get_contents($f['tmp_name']));
        return $this->back('/contacts', 'success', t('contacts.imported', ['n' => $n]));
    }

    public function export(): Response
    {
        return Response::download(Contacts::exportVcf($this->uid()), 'text/vcard; charset=utf-8', 'contacts.vcf');
    }
}
