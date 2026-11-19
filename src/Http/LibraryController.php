<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Offers\Offers;

/** Biblioteka firmy: referencje, osoby, certyfikaty, polisy i gotowe opisy - źródła dla ofert, wniosków i wiadomości. */
final class LibraryController extends Controller
{
    public function index(Request $r): Response
    {
        $items = Offers::library($this->orgId());
        $kind = array_key_exists($r->str('rodzaj'), Offers::LIBRARY_KINDS) ? $r->str('rodzaj') : 'reference';
        return $this->view('library/index', ['items' => $items, 'item' => null, 'kind' => $kind, 'title' => 'Biblioteka firmy']);
    }

    public function edit(Request $r): Response
    {
        $item = $this->db()->one('SELECT * FROM library_items WHERE org_id = ? AND id = ?', [$this->orgId(), (int) $r->param('id')]);
        if (!$item) {
            return $this->notFound();
        }
        return $this->view('library/index', ['items' => Offers::library($this->orgId()), 'item' => $item, 'kind' => $item['kind'], 'title' => 'Biblioteka firmy']);
    }

    public function save(Request $r): Response
    {
        $org = $this->orgId();
        $id = (int) $r->param('id');
        if ($id && !$this->db()->val('SELECT 1 FROM library_items WHERE org_id = ? AND id = ?', [$org, $id])) {
            return $this->notFound();
        }
        $kind = array_key_exists($r->str('kind'), Offers::LIBRARY_KINDS) ? $r->str('kind') : 'text';
        $title = mb_substr($r->str('title'), 0, 200);
        if ($title === '') {
            return $this->redirect($id ? '/biblioteka/' . $id : '/biblioteka?rodzaj=' . $kind, 'Podaj tytuł.', 'error');
        }
        $meta = [];
        foreach (['client', 'value', 'finished', 'role', 'qualifications'] as $k) {
            $v = $r->str('meta_' . $k);
            if ($v !== '') {
                $meta[$k] = $k === 'value' ? (float) str_replace([' ', ','], ['', '.'], $v) : mb_substr($v, 0, 300);
            }
        }
        $valid = $r->str('valid_until');
        $data = [
            'kind' => $kind,
            'title' => $title,
            'body' => mb_substr((string) ($r->post['body'] ?? ''), 0, 20000),
            'meta_json' => $meta ? json_out($meta) : null,
            'valid_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid) ? $valid : null,
            'updated_at' => now_utc(),
        ];
        if ($id) {
            $this->db()->update('library_items', $data, 'id = ? AND org_id = ?', [$id, $org]);
        } else {
            $this->db()->insert('library_items', $data + ['org_id' => $org, 'created_at' => now_utc()]);
        }
        return $this->redirect('/biblioteka?rodzaj=' . $kind, 'Zapisano w bibliotece. Użyjemy tego w kolejnych ofertach i wnioskach.');
    }

    public function delete(Request $r): Response
    {
        $this->db()->run('DELETE FROM library_items WHERE id = ? AND org_id = ?', [(int) $r->param('id'), $this->orgId()]);
        return $this->redirect('/biblioteka', 'Usunięto.');
    }
}
