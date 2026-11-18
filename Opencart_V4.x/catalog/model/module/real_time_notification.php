<?php
namespace Opencart\Catalog\Model\Extension\RealTimeNotification\Module;

class RealTimeNotification extends \Opencart\System\Engine\Model {

	/**
	 * Orders (in one of the success statuses of the settings) which are newer than the last one the visitor has seen.
	 * The first time only orders of the last 10 minutes are returned.
	 *
	 * @return array<int, array<string, mixed>> order_id => order
	 */
	public function getRecentSales(int $latest_order_id = 0, int $limit = 10): array {
		$limit = max(1, $limit);

		if ($latest_order_id <= 0) {
			$where = " AND o.`date_added` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
		} else {
			$where = " AND o.`order_id` > '" . (int)$latest_order_id . "'";
		}

		$statuses = array_filter(array_map('intval', (array)$this->config->get('module_real_time_notification_order_status')));

		if (!$statuses) {
			return [];
		}

		$where .= " AND o.`order_status_id` IN (" . implode(',', $statuses) . ")";

		// The payment address is optional in OpenCart 4 (off by default): fall back to the shipping address,
		// and to no place at all (an inner JOIN on the payment country / zone dropped all such orders)
		$sql = "SELECT o.`order_id`, o.`firstname`, o.`date_added`, COALESCE(NULLIF(o.`payment_city`, ''), NULLIF(o.`shipping_city`, ''), '') AS `city` FROM `" . DB_PREFIX . "order` o WHERE o.`store_id` = '" . (int)$this->config->get('config_store_id') . "'" . $where . " ORDER BY o.`order_id` ASC LIMIT " . (int)$limit;

		$sales = [];

		foreach ($this->db->query($sql)->rows as $order) {
			$order['order_products'] = $this->getOrderProducts((int)$order['order_id']);

			$sales[(int)$order['order_id']] = $order;
		}

		return $sales;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderProducts(int $order_id): array {
		return $this->db->query("SELECT op.`name`, op.`product_id`, p.`image` FROM `" . DB_PREFIX . "order_product` op LEFT JOIN `" . DB_PREFIX . "product` p ON (p.`product_id` = op.`product_id`) WHERE op.`order_id` = '" . (int)$order_id . "'")->rows;
	}
}
