<?php

use Bitrix\Main\Loader;

$description = array();

$isAvailable = true;

if (!Loader::includeModule('oplati.paysystem')) {
  $isAvailable = false;
}

$data = array(
  'NAME' => 'Оплати',
  'SORT' => 500,
  'IS_AVAILABLE' => $isAvailable
);
