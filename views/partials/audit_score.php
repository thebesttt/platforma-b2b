<?php /** @var array $v */ $s = (int) ($v['score'] ?? 0); $cls = $s >= 85 ? 'low' : ($s >= 60 ? 'mid' : 'high'); ?>
<span class="score <?= $cls ?>"><?= $s ?></span>
