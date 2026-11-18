<?php
require __DIR__ . '/../../_oc-test-harness/lib.php';

$db->exec("SET SESSION sql_mode = ''");

$ROUTE = 'extension/real_time_notification/module/real_time_notification';
function save(Client $a, string $t, array $s): array { global $ROUTE; return $a->post('/admin/index.php?route=' . $ROUTE . '.save&user_token=' . $t, $s); }
function conf(array $o = []): array {
	return $o + ['module_real_time_notification_status' => 1, 'module_real_time_notification_position' => 'bottom-left', 'module_real_time_notification_allow_dismiss' => 1, 'module_real_time_notification_pause_on_hover' => 0, 'module_real_time_notification_newest_on_top' => 0, 'module_real_time_notification_progress_bar' => 1, 'module_real_time_notification_delay' => '4000', 'module_real_time_notification_order_status' => [5]];
}
function add_order(array $o, array $products, string $age = '1 MINUTE'): int {
	$id = (int)sql_value('SELECT COALESCE(MAX(order_id), 0) + 1000 FROM oc_order');
	insert_row('oc_order', $o + ['order_id' => $id, 'store_id' => 0, 'firstname' => 'Test', 'lastname' => 'Buyer', 'payment_city' => '', 'shipping_city' => '', 'order_status_id' => 5, 'language_id' => 1, 'currency_code' => 'USD', 'currency_value' => 1, 'date_added' => date('Y-m-d H:i:s'), 'date_modified' => date('Y-m-d H:i:s')]);
	// OpenCart runs its MySQL session in the store timezone (UTC by default), seed the dates the same way (not with the NOW() of this connection)
	sql("UPDATE oc_order SET date_added = ? WHERE order_id = ?", [gmdate('Y-m-d H:i:s', strtotime('-' . $age)), $id]);
	foreach ($products as [$pid, $name]) { insert_row('oc_order_product', ['order_id' => $id, 'product_id' => $pid, 'name' => $name, 'model' => 'M', 'quantity' => 1, 'price' => 10, 'total' => 10, 'tax' => 0, 'reward' => 0]); }
	return $id;
}
function poll(Client $c): array { $r = $c->get('/index.php?route=extension/real_time_notification/module/real_time_notification.notification&language=en-gb'); return $r['json'] ?? ['error' => short($r['body'])]; }

echo "Real Time Notification end to end test (OpenCart $OC_VERSION)\n";
[$admin, $token] = admin_login();

if (wanted('install')) {
	section('1. Install');
	install_package($admin, $token);
	check('event for the footer is registered', (int)sql_value("SELECT COUNT(*) FROM oc_event WHERE code = 'real_time_notification' AND `trigger` = 'catalog/view/common/footer/after' AND status = 1") == 1);
}

if (wanted('settings')) {
	section('2. Settings');
	$p = $admin->get('/admin/index.php?route=' . $ROUTE . '&user_token=' . $token);
	check('settings page renders', $p['status'] == 200 && strpos($p['body'], 'id="form-real-time-notification"') !== false, short($p['body']));
	check('settings page: defaults of a never saved module (bottom right, newest on top and progress bar on)', (bool)preg_match('/value="bottom-right"[^>]*checked/', $p['body']) && (bool)preg_match('/name="module_real_time_notification_newest_on_top" value="1"[^>]*checked/', $p['body']) && (bool)preg_match('/name="module_real_time_notification_progress_bar" value="1"[^>]*checked/', $p['body']) && strpos($p['body'], 'Bottom Right') !== false);

	foreach (['abc', '100', '20000', ''] as $v) { $r = save($admin, $token, conf(['module_real_time_notification_delay' => $v])); check("delay '$v' is refused", isset($r['json']['error']['delay']), short($r['body'])); }
	$r = save($admin, $token, conf(['module_real_time_notification_position' => 'middle']));
	check('unknown position is refused', isset($r['json']['error']['position']), short($r['body']));

	$r = save($admin, $token, conf(['module_real_time_notification_order_status' => [5, 99999, 'x']]));
	check('valid settings are saved', !empty($r['json']['success']), short($r['body']));
	check('order statuses which do not exist are dropped', json_decode((string)sql_value("SELECT `value` FROM oc_setting WHERE `key` = 'module_real_time_notification_order_status'"), true) === [5]);
	check('"off" is stored as 0 (newest on top can be switched off now)', sql_value("SELECT `value` FROM oc_setting WHERE `key` = 'module_real_time_notification_newest_on_top'") === '0' && sql_value("SELECT `value` FROM oc_setting WHERE `key` = 'module_real_time_notification_progress_bar'") === '1');
	$p = $admin->get('/admin/index.php?route=' . $ROUTE . '&user_token=' . $token);
	check('settings page shows the saved values', (bool)preg_match('/value="bottom-left"[^>]*checked/', $p['body']) && !preg_match('/name="module_real_time_notification_newest_on_top" value="1"[^>]*checked/', $p['body']) && strpos($p['body'], 'value="4000"') !== false && (bool)preg_match('/value="5"[^>]*checked/', $p['body']));

	sql("DELETE FROM oc_user WHERE username = 'viewer'");
	sql("INSERT INTO oc_user_group SET name = 'Viewer', permission = ?", [json_encode(['access' => ['extension/real_time_notification/module/real_time_notification'], 'modify' => []])]);
	insert_row('oc_user', ['user_group_id' => (int)sql_value("SELECT MAX(user_group_id) FROM oc_user_group"), 'username' => 'viewer', 'password' => password_hash('viewer123', PASSWORD_DEFAULT), 'firstname' => 'V', 'lastname' => 'O', 'email' => 'v@example.com', 'image' => '', 'ip' => '', 'status' => 1, 'date_added' => date('Y-m-d H:i:s')]);
	[$viewer, $vt] = admin_login('viewer', 'viewer123');
	$r = save($viewer, $vt, conf(['module_real_time_notification_delay' => '9999']));
	check('saving without modify permission is refused', !empty($r['json']['error']['warning']) && sql_value("SELECT `value` FROM oc_setting WHERE `key` = 'module_real_time_notification_delay'") === '4000', short($r['body']));
}

if (wanted('notify')) {
	section('3. Notifications (real orders in the database)');
	save($admin, $token, conf());

	@mkdir($OC_DIR . '/image/catalog', 0777, true);
	imagepng(imagecreatetruecolor(300, 300), $OC_DIR . '/image/catalog/rtn-test.png');
	sql("DELETE FROM oc_product WHERE product_id = 110"); sql("DELETE FROM oc_product_description WHERE product_id = 110");
	insert_row('oc_product', ['product_id' => 110, 'master_id' => 0, 'model' => 'RTN', 'image' => 'catalog/rtn-test.png', 'quantity' => 5, 'stock_status_id' => 7, 'shipping' => 0, 'price' => 10, 'tax_class_id' => 0, 'manufacturer_id' => 0, 'date_available' => '2020-01-01', 'status' => 1, 'minimum' => 1, 'subtract' => 0, 'date_added' => date('Y-m-d H:i:s'), 'date_modified' => date('Y-m-d H:i:s')]);
	insert_row('oc_product_description', ['product_id' => 110, 'language_id' => 1, 'name' => 'Image product', 'description' => '', 'tag' => '', 'meta_title' => 'x', 'meta_description' => '', 'meta_keyword' => '']);
	sql("DELETE FROM oc_order"); sql("DELETE FROM oc_order_product");

	$a = add_order(['firstname' => 'Alice', 'payment_city' => 'London'], [[110, 'Image product'], [100, 'Plain product']], '5 MINUTE');
	$b = add_order(['firstname' => 'Bob', 'shipping_city' => 'Paris'], [[100, 'Plain product']], '4 MINUTE');
	$c = add_order(['firstname' => 'Cara'], [[100, 'X <script>alert(1)</script> "q"']], '3 MINUTE');
	$d = add_order(['firstname' => 'Dan', 'payment_city' => 'Rome', 'order_status_id' => 1], [[100, 'Plain product']], '3 MINUTE');
	$e = add_order(['firstname' => 'Eve', 'payment_city' => 'Oslo'], [[100, 'Plain product']], '30 MINUTE');
	$f = add_order(['firstname' => 'Fay', 'payment_city' => 'Bern', 'store_id' => 7], [[100, 'Plain product']], '3 MINUTE');
	$g = add_order(['firstname' => 'O&#039;Neil', 'payment_city' => 'Cork &amp; Co'], [[999999, 'Deleted product']], '2 MINUTE');

	$s = new_shopper();
	$s->get('/index.php?route=common/home&language=en-gb'); // creates the session
	$r = poll($s);
	$names = array_map(fn($x) => $x['message'], $r['sales'] ?? []);
	check('first poll: sales of the last 10 minutes in a success status of this store, oldest first, one per product', count($names) == 5 && array_column($r['sales'], 'oid') == [$a, $a, $b, $c, $g], short($r));
	check('each sale has its own name and city (the first sale was shown for all of them before)', strpos($names[0], 'Alice from London just bought') === 0 && strpos($names[2], 'Bob from Paris just bought') === 0 && strpos($names[3], 'Cara just bought') === 0, short($names));
	check('a sale with two products gives two notifications with the same buyer', strpos($names[1], 'Alice from London just bought') === 0 && strpos($names[0], 'Image product') !== false && strpos($names[1], 'Plain product') !== false);
	check('orders of other status / other store / older than 10 minutes are not shown', !preg_match('/Dan|Fay|Eve/', implode(' ', $names)));
	check('no city at all: text without "from"', strpos($names[3], 'from') === false);
	check('HTML in a product name is escaped', strpos($names[3], '<script>') === false && strpos($names[3], '&lt;script&gt;') !== false, $names[3]);
	check('values OpenCart already encoded are not encoded twice', strpos($names[4], "O&#039;Neil from Cork &amp; Co") === 0 && strpos($names[4], '&amp;#039;') === false && strpos($names[4], '&amp;amp;') === false, $names[4]);
	check('link of a notification points to the product page', strpos($r['sales'][0]['href'], 'product_id=110') !== false && strpos($names[0], 'href="' . $r['sales'][0]['href']) !== false || strpos($names[0], 'product_id=110') !== false, short($r['sales'][0]));
	check('thumbnail of the product image is reachable', $s->get($r['sales'][0]['thumb'])['status'] == 200, $r['sales'][0]['thumb']);
	check('product without image / deleted product: placeholder thumbnail is reachable', $s->get($r['sales'][1]['thumb'])['status'] == 200 && $s->get($r['sales'][4]['thumb'])['status'] == 200, short([$r['sales'][1]['thumb'], $r['sales'][4]['thumb']]));
	check('lid is the newest order', ($r['lid'] ?? 0) == $g);

	$r = poll($s);
	check('second poll: nothing new', $r['sales'] === [], short($r));
	$h = add_order(['firstname' => 'Hugo', 'payment_city' => 'Oslo'], [[100, 'Plain product']], '0 MINUTE');
	$r = poll($s);
	check('a new order is announced once, and only that one', count($r['sales']) == 1 && $r['sales'][0]['oid'] == $h && strpos($r['sales'][0]['message'], 'Hugo from Oslo') === 0, short($r));
	check('...and not again', poll($s)['sales'] === []);

	$s2 = new_shopper();
	check('another visitor gets the recent sales (own cursor)', count(poll($s2)['sales']) == 6);

	save($admin, $token, conf(['module_real_time_notification_order_status' => [1]]));
	$s3 = new_shopper();
	$r = poll($s3);
	check('status filter follows the settings', count($r['sales']) == 1 && strpos($r['sales'][0]['message'], 'Dan from Rome') === 0, short($r));
	save($admin, $token, conf(['module_real_time_notification_status' => 0]));
	check('module disabled: no sales', poll(new_shopper())['sales'] === []);
	save($admin, $token, conf());
}

if (wanted('footer')) {
	section('4. Footer (script, style, options)');
	sql('UPDATE oc_product SET manufacturer_id = 0 WHERE manufacturer_id IS NULL'); // OpenCart 4.1.0.0 fails on the product page of a product without manufacturer id
	save($admin, $token, conf(['module_real_time_notification_position' => 'top-center', 'module_real_time_notification_newest_on_top' => 0, 'module_real_time_notification_progress_bar' => 0, 'module_real_time_notification_delay' => '3000']));
	$s = new_shopper();
	$home = $s->get('/index.php?route=common/home&language=en-gb');
	$html = $home['body'];
	check('footer: stylesheet and script are added before </body>', preg_match('#<link[^>]+extension/real_time_notification/catalog/view/stylesheet/real_time_notification\.css#', $html) && preg_match('#<script[^>]+extension/real_time_notification/catalog/view/javascript/bootstrap-notify/bootstrap-notify\.min\.js#', $html) && strripos($html, 'real_time_notification.css') < strripos($html, '</body>'), short($html, 100));
	check('footer: added exactly once', substr_count($html, 'bootstrap-notify.min.js') == 1);
	preg_match('/var options = (\{.*?\});\n/s', $html, $m);
	$o = json_decode($m[1] ?? '', true);
	check('footer: options are valid JSON with the saved settings', is_array($o) && $o['placement'] == 'top' && $o['align'] == 'center' && $o['newest_on_top'] === false && $o['progress_bar'] === false && $o['allow_dismiss'] === true && $o['delay'] == 3000 && $o['interval'] == 5000, short($m[1] ?? $html, 200));
	check('footer: polling url uses the route of this OpenCart version', is_array($o) && strpos($o['url'], $OLD_SEP ? 'real_time_notification|notification' : 'real_time_notification.notification') !== false && strpos($o['url'], '&amp;') === false, short($o));
	foreach (['stylesheet/real_time_notification.css', 'javascript/bootstrap-notify/bootstrap-notify.min.js'] as $asset) {
		$a = $s->get('/extension/real_time_notification/catalog/view/' . $asset);
		check("asset $asset is served", $a['status'] == 200 && strlen($a['body']) > 100, 'status ' . $a['status']);
	}
	$other = $s->get('/index.php?route=product/product&product_id=100&language=en-gb');
	check('footer: also on other pages', strpos($other['body'], 'bootstrap-notify.min.js') !== false);

	save($admin, $token, conf(['module_real_time_notification_status' => 0]));
	$home = (new_shopper())->get('/index.php?route=common/home&language=en-gb');
	check('disabled: nothing is added', strpos($home['body'], 'real_time_notification') === false && $home['status'] == 200);
}

if (wanted('browser')) {
	section('5. Browser (headless Chrome, real notification on the store front)');
	$node = trim((string)shell_exec('command -v node'));
	$chrome = trim((string)shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
	$work = getenv('STRIPEPRO_TEST_DIR') ?: '/tmp/stripepro-test';

	if (!$node || !$chrome || !is_dir($work . '/node/node_modules/puppeteer-core')) {
		check('browser test skipped (needs node, chrome and puppeteer-core in ' . $work . '/node, see the Stripe Pro tests/run.sh --browser)', true);
	} else {
		save($admin, $token, conf(['module_real_time_notification_position' => 'bottom-left', 'module_real_time_notification_allow_dismiss' => 1, 'module_real_time_notification_progress_bar' => 1, 'module_real_time_notification_delay' => '10000']));
		sql("DELETE FROM oc_order"); sql("DELETE FROM oc_order_product");
		add_order(['firstname' => 'Browser Bob', 'payment_city' => 'Bristol'], [[110, 'Image product']], '1 MINUTE');
		$out = [];
		exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/browser.js') . ' ' . escapeshellarg($SITE) . ' ' . escapeshellarg($chrome) . ' 2>&1', $out, $code);
		echo implode("\n", $out) . "\n";
		check('browser test passes', $code === 0);
	}
}

if (wanted('uninstall')) {
	section('6. Uninstall');
	$u = $admin->get('/admin/index.php?route=extension/module.uninstall&user_token=' . $token . '&extension=' . $CODE . '&code=' . $NAME);
	check('uninstall succeeds', !empty($u['json']['success']), short($u['body']));
	check('uninstall removes the event', (int)sql_value("SELECT COUNT(*) FROM oc_event WHERE code = 'real_time_notification'") == 0);
	$home = (new_shopper())->get('/index.php?route=common/home&language=en-gb');
	check('storefront still works after uninstall', $home['status'] == 200 && strpos($home['body'], 'bootstrap-notify') === false);
}

finish();
