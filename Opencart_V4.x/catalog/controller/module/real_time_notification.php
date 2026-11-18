<?php
namespace Opencart\Catalog\Controller\Extension\RealTimeNotification\Module;

class RealTimeNotification extends \Opencart\System\Engine\Controller {

	/** milliseconds between two requests of a visitor */
	private const INTERVAL = 5000;

	/**
	 * Separator between the class and the method in a route: OpenCart 4.0.2.0 changed it from | to .
	 */
	private function separator(): string {
		return version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';
	}

	private function escape(string $value): string {
		// OpenCart already stores customer input HTML encoded, do not encode it twice
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
	}

	/**
	 * XHR: the sales the visitor has not been told about yet
	 */
	public function notification(): void {
		$json = ['sales' => []];

		if ($this->config->get('module_real_time_notification_status')) {
			$this->load->model('extension/real_time_notification/module/real_time_notification');
			$this->load->model('tool/image');
			$this->load->language('extension/real_time_notification/module/real_time_notification');

			$latest_order_id = (int)($this->session->data['real_time_notification']['latest_order_id'] ?? 0);

			$language = 'language=' . $this->config->get('config_language');

			$placeholder = is_file(DIR_IMAGE . 'placeholder.png') ? 'placeholder.png' : 'no_image.png';

			foreach ($this->model_extension_real_time_notification_module_real_time_notification->getRecentSales($latest_order_id) as $sale) {
				// every sale starts from the text again (the text of the first sale was reused for all the others before)
				$text = $sale['city'] !== '' ? $this->language->get('text_sale_notification') : $this->language->get('text_sale_notification_no_city');

				$text = str_replace(['{{firstname}}', '{{city}}'], [$this->escape((string)$sale['firstname']), $this->escape((string)$sale['city'])], $text);

				foreach ($sale['order_products'] as $product) {
					$href = $this->url->link('product/product', $language . '&product_id=' . (int)$product['product_id']);

					$name = sprintf($this->language->get('text_product_name'), $this->escape(html_entity_decode($href, ENT_QUOTES, 'UTF-8')), $this->escape((string)$product['name']));

					$image = (!empty($product['image']) && is_file(DIR_IMAGE . $product['image'])) ? $product['image'] : $placeholder;

					$json['sales'][] = [
						'oid'     => (int)$sale['order_id'],
						'thumb'   => (string)$this->model_tool_image->resize($image, 60, 60),
						'href'    => html_entity_decode($href, ENT_QUOTES, 'UTF-8'),
						'message' => str_replace('{{product}}', $name, $text)
					];
				}

				$latest_order_id = max($latest_order_id, (int)$sale['order_id']);
			}

			$this->session->data['real_time_notification']['latest_order_id'] = $latest_order_id;

			$json['lid'] = $latest_order_id;
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Event catalog/view/common/footer/after: adds the style, the script and the polling code in front of </body>.
	 *
	 * @param string       $route
	 * @param array<mixed> $data
	 * @param string       $output html of the footer
	 *
	 * @return string|null OpenCart before 4.1 uses the returned html, OpenCart 4.1 uses the $output reference
	 */
	public function footer(&$route, &$data, &$output) {
		if (!$this->config->get('module_real_time_notification_status') || !is_string($output) || strpos($output, 'real_time_notification') !== false) {
			return null;
		}

		$position = (string)$this->config->get('module_real_time_notification_position');

		if (!in_array($position, ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'])) {
			$position = 'bottom-right';
		}

		[$placement, $align] = explode('-', $position);

		// a setting which has never been saved is on by default, once saved the 0 / 1 of the admin counts
		$flag = fn(string $key, bool $default): bool => $this->config->has('module_real_time_notification_' . $key) ? (bool)$this->config->get('module_real_time_notification_' . $key) : $default;

		$delay = (int)$this->config->get('module_real_time_notification_delay');

		// root relative: works on http and https
		$base = rtrim((string)parse_url((string)$this->config->get('config_url'), PHP_URL_PATH), '/') . '/extension/real_time_notification/catalog/view/';

		$options = [
			'placement'     => $placement,
			'align'         => $align,
			'allow_dismiss' => $flag('allow_dismiss', false),
			'pause_on_hover' => $flag('pause_on_hover', false),
			'delay'         => $delay >= 500 && $delay <= 10000 ? $delay : 5000,
			'newest_on_top' => $flag('newest_on_top', true),
			'progress_bar'  => $flag('progress_bar', true),
			'interval'      => self::INTERVAL,
			'url'           => html_entity_decode($this->url->link('extension/real_time_notification/module/real_time_notification' . $this->separator() . 'notification', 'language=' . $this->config->get('config_language')), ENT_QUOTES, 'UTF-8')
		];

		$html = '<link rel="stylesheet" type="text/css" href="' . $base . 'stylesheet/real_time_notification.css"/>' . PHP_EOL
			. '<script type="text/javascript" src="' . $base . 'javascript/bootstrap-notify/bootstrap-notify.min.js"></script>' . PHP_EOL
			. $this->load->view('extension/real_time_notification/module/real_time_notification', ['options' => json_encode($options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)]);

		$position = strripos($output, '</body>');

		if ($position === false) {
			return null;
		}

		$output = substr_replace($output, $html, $position, 0);

		return $output;
	}
}
