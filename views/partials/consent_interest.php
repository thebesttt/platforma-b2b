<?php
/** Pytanie na stronie zgody, co firmę interesuje. @var string[] $topics tematy ustawione przez nadawcę @var array $in wpisane wartości */
$pickedNow = array_map('strval', (array) ($in['topics'] ?? []));
?>
<?php if ($topics): ?>
  <fieldset style="border:0;padding:0;margin:0">
    <legend class="label">Co Państwa interesuje? Opcjonalnie</legend>
    <?php foreach ($topics as $i => $t): ?>
      <label class="check"><input type="checkbox" name="topics[]" value="<?= (int) $i ?>"<?= in_array((string) $i, $pickedNow, true) ? ' checked' : '' ?>> <span><?= e($t) ?></span></label>
    <?php endforeach ?>
  </fieldset>
<?php endif ?>
<div class="field"><label for="zg-note">Wiadomość, opcjonalnie</label><textarea id="zg-note" name="note" rows="2" maxlength="500" placeholder="np. czego Państwo szukają i na kiedy"><?= e((string) ($in['note'] ?? '')) ?></textarea></div>
