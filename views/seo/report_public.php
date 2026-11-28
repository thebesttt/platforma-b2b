<?php
/** @var array $rep @var array $mr @var array $brand */
echo App\Core\View::partial('partials/monthly_report', ['rep' => $rep, 'mr' => $mr, 'brand' => $brand]);
