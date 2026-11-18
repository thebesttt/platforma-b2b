<div class="card stack-sm" style="text-align:center;padding:40px 24px">
  <h1><?= e($title ?? 'Błąd') ?></h1>
  <p class="muted" style="margin:0"><?= e($message ?? '') ?></p>
  <p style="margin:12px 0 0"><a class="btn ghost" href="<?= App\Core\Auth::check() ? '/pulpit' : '/' ?>">Wróć na stronę główną</a></p>
</div>
