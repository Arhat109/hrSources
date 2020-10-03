<?php
/**
 * МОДЕЛЬ: Генератор расположения кораблей на игровом поле.
 * 
 * получает список корабликов и расставляет их случайным образом.
 * Если списка нет, то использует типовой набор.
 * 
 * @TODO Занятые места в поле при сохранении в БД - не сохраняются! И потом их взять неоткуда...
 * 
 * После выполнения этого скрипта появляются:
 * константы
 *   FIELD_X,FIELD_Y -- размер поля. Начальная точка (1,1)
 * глобалы
 *   $field           -- Список занятых клеток на поле.
 *   $ships           -- Список расставленный кораблей.
 *   $defShipsGroups  -- набор допустимых кораблей по умолчанию
 *   $makeVisible     -- параметр генератора как расставлять корабли "видимыми или нет" (дабы не гонять через параметры функций)
 * функции
 *   setFieldString() -- занимает строку корабля на поле
 *   setShip()        -- расставляет корабль и создает его описание
 *   setShipGroups()  -- расставляет все кораблики группы с зачисткой глобалов $field, $ships.
 *   fieldIsEmpty()   -- проверяет свободность места при генерации набора.
 *   loadShips()      -- получает данные о кораблях из `seewar`.`ships` в $ships
 *   saveShips()      -- сохраняет данные о кораблях там же из $ships
 *   delShips()       -- удаляет заданную конфигурацию из БД
 *   getShipOptions() -- отдает список всех конфигураций в БД для вывода опций в селекторе выбора
 *
 * @param $_REQUEST['s'] -- комплект кораблей или использует типовой набор.
 * 
 * @author fvn-20140201..
 */

// Для модели: размеры игрового поля (начало: 0,0; конец +1,+1) - поле с учетом обрамляющих клеток!
define('FIELD_X', 10);
define('FIELD_Y', 10);
define('ONE_X', 32);	// размер клетки x,y для вида
define('ONE_Y', 32);
define('FIELD_IMG', '/public/images/seewar/see.gif');      // фоновая картинка поля для вида
define('SHIP_IMG' , '/public/images/seewar/ship');         // префикс картинок кораблей @example ship3v.jpg
define('HOLE_IMG' , '/public/imagex/seewar/hole.png');     // картинка пробоины корабля

define('TBL_FIELD', '`ships`');                            // название таблицы хранения данных

/**
 * Набор по умолчанию: каких кораблей и сколько
 *  
 * @var array
 */
$defShipGroups = array(4=>1, 3=>2, 2=>3, 1=>4);

$field = array();
$ships = array();
$makeVisible = true;

// echo '<br/>AFTER DECLARE: <pre>'; var_dump($ships); echo '<br/>';

/**
 * Поставить корабль на глобальное поле и запомнить его данные в глобале.
 * 
 * Если корабль вертикальный заполняем по три точки сверху-вниз
 * , иначе заполняем слева-направо, просто поменяв координаты
 * 
 * @param int $size -- размер корабля
 * @param int $x    -- координата левее корабля
 * @param int $y    -- координата правее корабля
 * @param int $v    -- направление вертикально/горизонтально
 */
function setShip($size, $x, $y, $v)
{
	global $ships, $field, $makeVisible;

	$startX = $x+1; $startY = $y+1;

	for( $i=$size+2; $i>0; $i--)
	{
//echo "[{$v}:{$x}={$y}]";
		$field[$x][$y] = true;
		if( $v == 'v' ) {
			$field[$x+1][$y] = true;
			$field[$x+2][$y] = true;
			$y++;
		} else {
			$field[$x][$y+1] = true;
			$field[$x][$y+2] = true;
			$x++;
		}
	}
	$ships[] = array(
		'size'=>$size, 'x'=>$startX, 'y'=>$startY, 'v'=>$v, 'img'=>SHIP_IMG . "{$size}{$v}.png"
		, 'visible' => $makeVisible, 'holes'=>array()
	);
//echo ' added.';
}

/**
 * Проверяет свободность места заданного размера и направления
 * от клетки левее и правее корабля.
 * 
 * @param int $size -- размер корабля
 * @param int $x    -- левее левой клетки
 * @param int $y    -- правее правой клетки
 * @param int $v    -- направление корабля
 * 
 * @return bool
 */
function fieldIsEmpty($size, $x, $y, $v)
{
	global $field;

	for($size; $size>0; $size--)
	{
//echo " {{$x}-{$y}}";
		if( isset($field[$x][$y]) && $field[$x][$y] ) return false;

		if( $v == 'v' ) $y++;
		else            $x++;
	}
//echo ' empty.';
	return true;
}

/**
 * Расставляет корабли по полю, заполняя список кораблей свойствами.
 * 
 * @param array $groups -- список каких и сколько кораблей ставить. Или типовой @see $defShipsGroups
 */
function setShipGroups( array $groups = array() )
{
	global $ships, $field, $defShipsGroups;

	if( empty($groups) ) $groups = $defShipsGroups;
	if( !empty($field)  ) $field = array();
	if( !empty($ships)  ) $ships = array();

	$maxCount = 10000;
	foreach( $groups as $size=>$count )
	{
		for( $i=$count; $i>0; $i--) {
			do {
				// задаемся произвольным левым-верхним углом области корабля и направлением:
				$v=(rand(0, 100) >= 50? 'v': 'g');
				$x=rand(1, FIELD_X - ($v == 'g'? $size-1 : 0));
				$y=rand(1, FIELD_Y - ($v == 'v'? $size-1 : 0));
//echo "<br/>({$size}-{$count}:{$v},{$x},{$y}) ";
			} while( !fieldIsEmpty($size, $x, $y, $v) && --$maxCount>0);
			setShip($size, $x-1, $y-1, $v);			// добавляет описание кораблика в глобал $ships!
		}
	}
//echo '<br/>ALL SHIP IN FUNCTION<pre>'; var_dump($ships); echo '</pre><br/>';
	return $ships;
}

/**
 * Функция вида модели поля с кораблями.
 * 
 * картинку с полем ставим фоном в див поля и на ней расставляем корабли с заданным шагом и дырками на них
 * 
 * @param bool $isWithShips -- ставить корабли на поле
 * @param bool $isWithHoles -- ставить дырки на корабли
 * 
 * @return html-string
 */
function getFieldView( $isWithShips, $isWithHoles)
{
	global $ships;

	//@TODO добавить после модели учета дырок:	onclick=\"shooting();\"
	$fx = 200; $fy = 200;
	$content = 
		'<div id="field" style="border: 1px solid silver; position:absolute; top:'.$fx.'px; left:'.$fy.'px;
				background:url('. FIELD_IMG . '); width:' . ONE_X*FIELD_X . 'px; height:' . ONE_X*FIELD_X . 'px;"
		>'
	;
	// если задано - выводим корабли на поле, иначе выйдет пустое поле:
	if( $isWithShips )
		foreach($ships as $num=>$ship)
//			if( $ship['visible'] )
			{
				$content .= 
						'<img id="ship-' . $num . '" src="' . $ship['img']. '" class="ships"'
							. ' style="position:absolute; z-index:'. (1000 + $num)
							. '; top:' . (ONE_Y*($ship['y']-1)) . 'px; left:' . (ONE_X*($ship['x']-1)) . 'px;"'
						.' alt="ship-' . $num . '"/>'
				;
				// Если кораблик ранен или подбит - выводим крестики на полях корабля:
				// @TODO доделать! Нет модели учета дырок в кораблях!
				if( $isWithHoles )
					foreach($ships['holes'] as $hole)
					{
						$content .=
							'<img src="' . HOLE_IMG . '" class="holes" style="position:relative;
								top:' . ONE_Y*$hole['y'] . 'px; left:' . ONE_X*$hole['x'] . 'px;"
							/>'
						;
					}
			}
	return $content . '</div>';
}

/**
 * Загрузка описания кораблей из таблички по заданному идентификатору
 * 
 * @param int $id
 * 
 * @return array
 */
function loadShips($id)
{
	$res=pdoSelectAll('SELECT `jsonData` FROM '.TBL_FIELD.' WHERE `id`= ?', array($id));
	if( $res ) {
//die(var_dump($res, $res[0]['jsonData'], json_decode($res[0]['jsonData'], true)));
		return json_decode($res[0]['jsonData'], true);
	}
	return array();
}

/**
 * Записываем в БД набор корабликов в виде json строки данных. Комплект един - незачем писать по отдельности
 * 
 * @param $data -- записываемый набор
 * 
 * @return {false|int} номер последнего набора если записал
 */
function saveShips( $data )
{
	global $pdo;
	$newId = false;
	$jsnData = json_encode($data);

	try {
    	$stmt = $pdo->prepare('INSERT IGNORE INTO '.TBL_FIELD.' (`jsonData`, `md5`) VALUES(?, ?)');
        $pdo->beginTransaction();
        $stmt->execute( array($jsnData, md5($jsnData)) );
        $newId = $pdo->lastInsertId();
        $pdo->commit();
    } catch(PDOExecption $e) { $pdo->rollBack(); pdoCatch(); }
    return $newId;
}

/**
 * Возвращает html список options для показа в селекторе выбора набора для загрузки из БД
 * 
 * @return html-string
 */
function getShipOptions()
{
	$content = '';
	$rows = pdoSelectAll('SELECT `id` FROM ' . TBL_FIELD);
	if( $rows )
		foreach( $rows as $r ) {
			$content .= '<option value="'.$r['id'].'">Набор '.$r['id'].'</option>';
	}
	return $content;
}

/**
 * Удаляет заданный набор кораблей из БД
 * @TODO доделать!
 * 
 * @param int $id
 * 
 * @return bool
 */
function delShips($id)
{
	return false;
}
