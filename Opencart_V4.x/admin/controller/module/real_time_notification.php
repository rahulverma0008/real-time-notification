<?php
namespace Opencart\Admin\Controller\Extension\RealTimeNotification\Module;

class RealTimeNotification extends \Opencart\System\Engine\Controller {

	private const EVENT_CODE = 'real_time_notification';

	private const POSITIONS = ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'];

	/**
	 * Separator between the class and the method in a route: OpenCart 4.0.2.0 changed it from | to .
	 */
	private function separator(): string {
		return version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';
	}

	private function getAction(): string {
		return 'extension/real_time_notification/module/real_time_notification' . $this->separator() . 'footer';
	}

	/**
	 * Register the event which adds the notification script to the footer. Plain SQL because the addEvent() method of the
	 * setting/event model differs between OpenCart versions. Safe to call more than once.
	 */
	private function addEvent(): void {
		$this->deleteEvent();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = '" . self::EVENT_CODE . "', `description` = 'Real Time Notification: script in the footer', `trigger` = 'catalog/view/common/footer/after', `action` = '" . $this->db->escape($this->getAction()) . "', `status` = '1', `sort_order` = '0'");
	}

	private function deleteEvent(): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "'");
	}

	public function install(): void {
		$this->addEvent();
	}

	public function uninstall(): void {
		$this->deleteEvent();
	}

	public function index(): void {
		$this->load->language('extension/real_time_notification/module/real_time_notification');

		$this->document->setTitle($this->language->get('heading_title'));

		// the event is missing when the files have been replaced without reinstalling the extension
		if (!$this->db->query("SELECT `event_id` FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "' AND `action` = '" . $this->db->escape($this->getAction()) . "'")->num_rows) {
			$this->addEvent();
		}

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/real_time_notification/module/real_time_notification', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/real_time_notification/module/real_time_notification' . $this->separator() . 'save', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');
		$data['support_email'] = $this->language->get('support_email');

		// defaults of a module which has never been saved
		$flag = fn(string $key, int $default): int => $this->config->has('module_real_time_notification_' . $key) ? (int)(bool)$this->config->get('module_real_time_notification_' . $key) : $default;

		$position = (string)$this->config->get('module_real_time_notification_position');

		$data['module_real_time_notification_position'] = in_array($position, self::POSITIONS) ? $position : 'bottom-right';
		$data['module_real_time_notification_allow_dismiss'] = $flag('allow_dismiss', 0);
		$data['module_real_time_notification_pause_on_hover'] = $flag('pause_on_hover', 0);
		$data['module_real_time_notification_newest_on_top'] = $flag('newest_on_top', 1);
		$data['module_real_time_notification_progress_bar'] = $flag('progress_bar', 1);
		$data['module_real_time_notification_status'] = $flag('status', 0);
		$data['module_real_time_notification_delay'] = (int)$this->config->get('module_real_time_notification_delay') ?: 5000;
		$data['module_real_time_notification_order_status'] = array_map('intval', (array)$this->config->get('module_real_time_notification_order_status'));

		$data['positions'] = self::POSITIONS;

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/real_time_notification/module/real_time_notification', $data));
	}

	public function save(): void {
		$this->load->language('extension/real_time_notification/module/real_time_notification');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/real_time_notification/module/real_time_notification')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post = $this->request->post;

		$delay = trim((string)($post['module_real_time_notification_delay'] ?? ''));

		if (!ctype_digit($delay) || (int)$delay < 500 || (int)$delay > 10000) {
			$json['error']['delay'] = $this->language->get('error_delay');
		}

		if (!in_array($post['module_real_time_notification_position'] ?? '', self::POSITIONS)) {
			$json['error']['position'] = $this->language->get('error_position');
		}

		if (isset($json['error']) && !isset($json['error']['warning'])) {
			$json['error']['warning'] = $this->language->get('error_warning');
		}

		if (!$json) {
			$this->load->model('localisation/order_status');

			$valid = array_column($this->model_localisation_order_status->getOrderStatuses(), 'order_status_id');

			$statuses = [];

			foreach ((array)($post['module_real_time_notification_order_status'] ?? []) as $order_status_id) {
				if (in_array($order_status_id, $valid)) {
					$statuses[] = (int)$order_status_id;
				}
			}

			$settings = [
				'module_real_time_notification_status'         => (int)!empty($post['module_real_time_notification_status']),
				'module_real_time_notification_position'       => $post['module_real_time_notification_position'],
				// stored as 0 / 1: "off" has to be remembered, it is not the same as "never saved"
				'module_real_time_notification_allow_dismiss'  => (int)!empty($post['module_real_time_notification_allow_dismiss']),
				'module_real_time_notification_pause_on_hover' => (int)!empty($post['module_real_time_notification_pause_on_hover']),
				'module_real_time_notification_newest_on_top'  => (int)!empty($post['module_real_time_notification_newest_on_top']),
				'module_real_time_notification_progress_bar'   => (int)!empty($post['module_real_time_notification_progress_bar']),
				'module_real_time_notification_delay'          => (int)$delay,
				'module_real_time_notification_order_status'   => $statuses
			];

			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('module_real_time_notification', $settings);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
