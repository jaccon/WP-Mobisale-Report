<?php


if ( !defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

ob_start();

global $sr_text_domain;

$sr_text_domain = ( defined('SR_TEXT_DOMAIN') ) ? SR_TEXT_DOMAIN : 'smart-reporter-for-wp-e-commerce';

if ( empty( $wpdb ) || !is_object( $wpdb ) ) {
    require_once ABSPATH . 'wp-includes/wp-db.php';
}

// include_once ('../../../../wp-load.php');
// include_once ('../../../../wp-includes/wp-db.php');
include_once (ABSPATH . WPINC . '/functions.php');
// include_once ('reporter-console.php'); // Included for using the sr_number_format function


//Function to convert the Sales Figures
function sr_number_format($input, $places)
{

    $suffixes = array('', 'k', 'm', 'b', 't');
    $suffixIndex = 0;
    $mult = pow(10, $places);


    if ( defined('SR_NUMBER_FORMAT') && SR_NUMBER_FORMAT == 1 ) {
    	return (
        $input > 0
            // precision of 3 decimal places
            
            ? floor($input * $mult) / $mult
            : ceil($input * $mult) / $mult
        );
    }

    while(abs($input) >= 1000 && $suffixIndex < sizeof($suffixes))
    {
        $suffixIndex++;
        $input /= 1000;
    }

    return (
        $input > 0
            // precision of 3 decimal places
            
            ? floor($input * $mult) / $mult
            : ceil($input * $mult) / $mult
        )
        . $suffixes[$suffixIndex];
}


//Function to sort multidimesnional array based on any given key
function sr_multidimensional_array_sort($array, $on, $order='ASC'){

    $sorted_array = array();
    $sortable_array = array();

    if (count($array) > 0) {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $key2 => $value2) {
                    if ($key2 == $on) {
                        $sortable_array[$key] = $value2;
                    }
                }
            } else {
                $sortable_array[$key] = $value;
            }
        }

        switch ($order) {
            case 'ASC':
                asort($sortable_array);
                break;
            case 'DESC':
                arsort($sortable_array);
                break;
        }

        foreach ($sortable_array as $key => $value) {
            $sorted_array[$key] = $array[$key];
        }
    }

    return $sorted_array;
}


// =============================================================================
// Code For SR Beta
// =============================================================================

	$del = 3;
	$result  = array ();
	$encoded = array ();
	$months  = array ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
	$cat_rev = array ();

	global $wpdb;

	if (isset ( $_GET ['start'] ))
	    $offset = $_GET ['start'];
	else
	    $offset = 0;

	if (isset ( $_GET ['limit'] ))
	    $limit = $_GET ['limit'];

	// For pro version check if the required file exists
	// if (file_exists ( '../pro/sr-woo.php' )){
	//     define ( 'SRPRO', true );
	// } else {
	//     define ( 'SRPRO', false );
	// }

	//Function for sorting and getting the top 5 values
	function usort_callback($a, $b)
	{
	  if ( $a['calc_total'] == $b['calc_total'] )
	    return 0;

	  return ( $a['calc_total'] > $b['calc_total'] ) ? -1 : 1;
	}


	//function to get the abandoned products
	function sr_get_abandoned_products ($params) {

		if ( ! wp_verify_nonce( $params['security'], 'smart-reporter-security' ) ) {
     		die( 'Security check' );
     	}

     	global $wpdb;

     	$returns = array();

     	$pid_cond = ( !empty($params['pid_cond']) ) ? ' AND product_id IN ('. implode(",",$params['pid_cond']) .') ' : '';

     	$cart_table_name = "{$wpdb->prefix}woo_sr_cart_items";
     	$items_table_name = "{$wpdb->prefix}woo_sr_order_items";
		if ( $wpdb->get_var( "show tables like '$cart_table_name'" ) == $cart_table_name 
			&& $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {

			$query = $wpdb->prepare ("SELECT ci.product_id as id,
											IFNULL(ci.aqty,0) as aqty, 
											CASE WHEN IFNULL(qty_in_orders_placed, 0) = 0 THEN 100 
												WHEN qty_in_orders_placed >= added_to_cart THEN 0 
												ELSE ((added_to_cart-qty_in_orders_placed)/added_to_cart)*100 END as abandonment_rate,
											IFNULL(oi.sales,0) as sales,
											IFNULL(added_to_cart,0) as added_to_cart,
											IFNULL(oi.lod,'-') as lod
											FROM 
												(SELECT product_id, 
													SUM(qty) as added_to_cart,
													SUM(CASE WHEN cart_is_abandoned = 1 THEN qty END) as aqty
												FROM {$wpdb->prefix}woo_sr_cart_items
												WHERE last_update_time between unix_timestamp('%s') and unix_timestamp('%s')
													".$pid_cond."
												GROUP BY product_id) AS ci 
												LEFT JOIN 
												(SELECT CASE WHEN variation_id > 0 THEN variation_id
															ELSE product_id
														END AS id,
													SUM(qty) as qty_in_orders_placed,
													SUM(total) as sales,
													MAX(order_date) as lod
													FROM {$wpdb->prefix}woo_sr_order_items 
													WHERE order_date BETWEEN '%s' AND '%s'
													AND order_is_sale = 1 
													AND type IN ('S', 'R')
													AND trash = 0 
												GROUP BY id) AS oi 
												ON (ci.product_id = oi.id )
										ORDER BY abandonment_rate DESC
										".$params['limit'],$params['cp_start_date'], $params['cp_end_date'].' 23:59:59',$params['cp_start_date'], $params['cp_end_date']);

			$results = $wpdb->get_results($query, 'ARRAY_A');
		}

		$returns['a_flag'] = false; // flag for determining if any product has been abandoned or not
		$returns['a_prod'] = array();

		if ( count($results) > 0 ) {
			foreach ( $results as $row ) {
				$returns['a_prod'][] = array( 'id' => $row['id'],
												'title' => '-',
												'sales' => (empty($params['limit'])) ? sr_number_format($row['sales'],get_option( 'woocommerce_price_num_decimals' )) : $row['sales'],
												'aqty' => $row['aqty'],
												'added_to_cart' => $row['added_to_cart'],
												'arate' => $row['abandonment_rate'],
												'lod' => $row['lod']);

				if ( !empty($row['aqty']) ) {
					$returns['a_flag'] = true;
				}
			}	
		}

		return $returns;
	}

	//function to get the product title
	function sr_get_prod_title($data, $params) {

		if ( ! wp_verify_nonce( $params['security'], 'smart-reporter-security' ) ) {
     		die( 'Security check' );
     	}

     	global $wpdb;

		if( count($params['t_p_ids']) > 0 ) {
			$query = "SELECT id,
						CASE WHEN post_parent = 0 THEN post_title END as title
					FROM {$wpdb->prefix}posts
					WHERE id IN (". implode(",",$params['t_p_ids']) .")
					GROUP BY id";
			$results = $wpdb->get_results($query, 'ARRAY_A'); 

			if ( count($results) > 0 ) {
				
				foreach ( $results as $row ) {

					// assigning products titles
					foreach ( $data as $key => $arr ) {

						$v_ids = $params['t_v_ids'];

						foreach ($arr as $key1 => $value) {

							// $index = array_search($row['id'], $v_ids);
							$index = (!empty($v_ids[$value['id']])) ? $value['id'] : 0;

							if ( !empty($row['id']) && $value['id'] == $row['id'] ) {
								$data[$key][$key1]['title'] = $row['title'];
							} else if ( !empty($index) && $v_ids[$index] == $row['id'] ) {
								$data[$key][$key1]['title'] = $row['title'];
								unset($v_ids[$index]);
							}
							
						}
					}
				}
			}
		}

		if( count($params['t_v_ids']) > 0 ) { 
			//Code to get the attribute terms for all attributes
	        $query = "SELECT terms.name AS name,
                            terms.slug AS slug,
                            taxonomy.taxonomy as taxonomy
                        FROM {$wpdb->prefix}terms as terms
                            JOIN {$wpdb->prefix}term_taxonomy as taxonomy ON (taxonomy.term_id = terms.term_id)
                        WHERE taxonomy.taxonomy LIKE 'pa_%'
                        GROUP BY taxonomy.taxonomy, terms.slug";
	        $results = $wpdb->get_results( $query, 'ARRAY_A' );

	        $p_att = array();

	        if ( count($results) > 0 ) {
	        	foreach ($results as $row) {
		            if ( empty($p_att[$row['taxonomy']]) ) {
		                $p_att[$row['taxonomy']] = array();               
		            }
		            $p_att[$row['taxonomy']][$row['slug']] = $row['name'];
		        }	
	        }

	        //  code to get the attribute labels
	        $query = "SELECT attribute_name, attribute_label
	                    FROM {$wpdb->prefix}woocommerce_attribute_taxonomies";
	        $results = $wpdb->get_results( $query, 'ARRAY_A' );

	        $a_lbl = array();

	        if ( count($results) > 0 ) {
	        	foreach ($results as $row) {
		            $a_lbl['pa_' . $row['attribute_name']] = $row['attribute_label'];
		        }	
	        }

	        // code to get the variation att.
	        $query = "SELECT post_id as id,
	        			meta_key as mkey,
	        			meta_value as value
	        		FROM {$wpdb->prefix}postmeta
	        		WHERE meta_key LIKE 'attribute_%'
	        			AND post_id IN (". implode(",", array_keys($params['t_v_ids']) ) .")
	        		GROUP BY id, mkey";
	        $results = $wpdb->get_results( $query, 'ARRAY_A' );

	        if ( count($results) > 0 ) {

	        	$id = $results[0]['id'];
	        	$vt = ' (';

	        	for ($i=0; $i<count($results); $i++) {

	        		$a = ( strpos($results[$i]['mkey'], 'attribute_') !== false ) ? substr($results[$i]['mkey'], strlen('attribute_')) : '';

	        		$vt .= ( $vt != ' (' ) ? ' , ' : '';
	        		$vt .= (!empty($a_lbl[$a])) ? ($a_lbl[$a] .' : '. $p_att[$a][$results[$i]['value']]) : ($results[$i]['mkey'] .' : '. $results[$i]['value']);

	        		$id = $results[$i]['id'];
	        		$id_nxt = (!empty($results[$i+1]['id'])) ? $results[$i+1]['id'] : '';

	        		if ( $id != $id_nxt || $i == (count($results)-1) ) {

	        			$vt .= ')';

	        			// assigning variation attributes to titles
						foreach ( $data as $key => $arr ) {
							foreach ($arr as $key1 => $value) {
								if ( !empty($value['id']) && ! empty( $results[$i]['id'] ) && $value['id'] == $results[$i]['id'] ) {
									$data[$key][$key1]['title'] .= $vt;
								}	
							}
						}
						$vt = ' (';
	        		}
	        	}
	        }
		}
	}

	//function to query and get top product and top product detailed view data
	function sr_get_top_prod_data( $params ) {

		if ( ! wp_verify_nonce( $params['security'], 'smart-reporter-security' ) ) {
     		die( 'Security check' );
     	}

		global $wpdb, $sr_text_domain;

		$chart_keys = array();
		$tp_detailed_select = $tp_detailed_join = '';

		$prefix = ( !empty($params['order_by']) && $params['order_by'] == 'qty' ) ? 'tpq_' : 'tps_';
		$select_sum = ( !empty($params['order_by']) && $params['order_by'] == 'qty' ) ? ' ,SUM( qty ) as qty ' : ' ,SUM( total ) as sales ';


		if( !empty($params['post']['cmd']) && $params['post']['cmd'] == 'top_prod_detailed' ) {

			$prefix = 'tpd_';

			$tp_detailed_select = " ,IFNULL(SUM( CASE WHEN oi.type = 'R' THEN -1*oi.total END ), 0) AS r_sales
								    ,IFNULL(SUM( CASE WHEN oi.type = 'R' THEN oi.qty END ), 0) AS r_qty
								    ,IFNULL(SUM( CASE WHEN oi.type = 'S' AND so.type = 'shop_order' THEN 1 ELSE 0 END ), 0) as orders_count
								    ,IFNULL(SUM( CASE WHEN oi.type = 'S' AND so.type = 'shop_order' AND so.status = 'wc-completed' THEN 1 ELSE 0 END ), 0) as corders_count
								    ,IFNULL(SUM( CASE WHEN oi.order_is_sale = 1 AND oi.type = 'S' AND so.type = 'shop_order' THEN 1 ELSE 0 END ), 0) as sale_orders_count";

			$tp_detailed_join = " JOIN {$wpdb->prefix}woo_sr_orders as so ON (so.order_id = oi.order_id
													                				AND so.trash = 0)";
		}

		$results = array();

		$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
     	$items_table_name = "{$wpdb->prefix}woo_sr_order_items";
		if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name 
			&& $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {

			$query = $wpdb->prepare ("SELECT oi.product_id as product_id, 
											oi.variation_id as variation_id,
										   IFNULL(SUM( CASE WHEN oi.order_is_sale = 1 AND (oi.type = 'S' OR oi.type = 'R') THEN oi.total END ), 0) as sales,
										   IFNULL(SUM( CASE WHEN oi.order_is_sale = 1 AND (oi.type = 'S' OR oi.type = 'R') THEN oi.qty END ), 0) as qty
										   ".$tp_detailed_select."
									FROM {$wpdb->prefix}woo_sr_order_items oi
										   ".$tp_detailed_join."
									WHERE oi.order_date BETWEEN '%s' AND '%s'
										AND oi.trash = 0
									GROUP BY product_id, variation_id
									ORDER BY ". $params['order_by'] ." DESC ".$params['limit'], $params['cumm_dates']['cp_start_date'], $params['cumm_dates']['cp_end_date']);

			$results = $wpdb->get_results($query, 'ARRAY_A');
		}

		$returns = array( 	'kpi' => array(), 
							'chart' => array(),
							't_p_ids' => array(),
							't_v_ids' => array());

		if ( count($results) > 0 ) {

			$keys = array();
			$params['cumm_dates']['c_mins'] = round(((strtotime($params['cumm_dates']['cp_end_date']) - strtotime($params['cumm_dates']['cp_start_date'])) / 60), 2);

			foreach ( $results as $row ) {
				$id = $row['product_id'];

				$returns['t_p_ids'] [] = $id;

				if (!empty($row['variation_id'])) {
					$returns['t_v_ids'] [$row['variation_id']] = $row['product_id'];
					$id = $row['variation_id'];
				}

				$temp = array( 'id' => $id,
								'title' => '-',
								'sales' => $row['sales'], 
								'qty' => $row['qty'] );

				if( !empty($params['post']['cmd']) && $params['post']['cmd'] == 'top_prod_detailed' ) {

					if( $temp['sales'] <= 0 || $temp['qty'] <= 0 ) {
						continue;
					}

					$temp['r_sales'] = (!empty($row['r_sales'])) ? $row['r_sales'] : 0;
					$temp['r_qty'] = (!empty($row['r_qty'])) ? $row['r_qty'] : 0;					
					$temp['orders_count'] = !empty($row['orders_count']) ? $row['orders_count'] : 0;
					$temp['corders_count'] = !empty($row['corders_count']) ? $row['corders_count'] : 0;

					//refund rate calculation
					$temp['r_rate'] = 0;
					if( !empty($temp['r_sales']) ) {
						if( $temp['r_sales'] > 0 ) {
							$temp['r_rate'] = ($temp['r_sales']/$temp['sales'])*100;
						}
					}

					$temp['avg_sales'] = (!empty($temp['sales'])) ? (($temp['sales'] / $params['cumm_dates']['c_mins']) * 1440) : 0;
					$temp['f_sales'] = (!empty($temp['avg_sales'])) ? $temp['avg_sales'] * $params['cumm_dates']['cp_diff_dates'] : 0;
					$temp['freq_sales'] = sr_get_frequency_formatted( (!empty($row['sale_orders_count'])) ? (($params['cumm_dates']['c_mins'] / 1440) / $row['sale_orders_count']) : 0 );

					$image = wc_placeholder_img_src();
					$image = str_replace( ' ', '%20', $image );
					$temp['thumb_url'] = '<img src="' . esc_url( $image ) . '" alt="' . esc_attr__( 'Thumbnail', $sr_text_domain ) . '" class="wp-post-image" height="48" width="48" />';

				}

				$returns['kpi']['tpd_'.$id] = $temp;
				$keys[] = $id;
			}

			$tp_ids = $keys;
			// array_walk($keys, function(&$value, $key) { $value = 'tps_'. $value; });
			array_walk($keys, 'format_top_prod_keys', $prefix);
			$chart_keys = ( count($keys) > 0 ) ? array_merge($chart_keys, $keys) : $chart_keys;

			$prod_cond = (!empty($t_p_ids)) ? ' AND ( (product_id IN ('. implode(",",$t_p_ids) .') AND variation_id = 0)' : '';
			$prod_cond .= (!empty($t_v_ids)) ? ( (!empty($prod_cond)) ? ' OR ' : ' AND ( ' ) . 'variation_id IN ('. implode(",",array_keys($t_v_ids)) .')' : '';
			$prod_cond .= (!empty($prod_cond)) ? ' ) ' : '';

			//  Query to get the dates wise sales for the top products
			$tp_results = array();

	     	$items_table_name = "{$wpdb->prefix}woo_sr_order_items";
			if ( $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {
	
				$query = $wpdb->prepare ("SELECT CASE WHEN variation_id > 0 THEN variation_id
													ELSE product_id
													END AS ".$prefix."id,
												concat(DATE_FORMAT(".$params['date_col'].", '%s'), '".$params['time_str']."') AS period
												".$select_sum."
										FROM {$wpdb->prefix}woo_sr_order_items 
										WHERE order_date BETWEEN '%s' AND '%s'
											AND order_is_sale = 1
											AND (type = 'S' OR type = 'R')
											AND trash = 0
											".$prod_cond."
										GROUP BY order_date, ".$prefix."id", $params['cumm_dates']['format'], $params['cumm_dates']['cp_start_date'], $params['cumm_dates']['cp_end_date']);

				$tp_results = $wpdb->get_results($query, 'ARRAY_A');
			}

			// Initialize chart data to 0

			if ( count($tp_results) > 0 ) {
				foreach ($chart_keys as $value) {
					$returns['chart'][$value] = array_fill(0, $params['periods_count'], 0);
				}
			}

			// Loop and assign the chart data
			if ( count($tp_results) > 0 ) {

				foreach ($tp_results as $row) {

					if (!array_key_exists($row['period'], $params['p2i']) ) {
						error_log('Smart Reporter: Invalid value for "period" in DB results - '.$row['period']);
						continue;
					}

					// Index of this period - this will be used to position different chart data at this period's index
					$i = $params['p2i'][ $row['period'] ];

					// Set values in charts
					if ( !empty($row[$prefix.'id']) ) {
						if( !isset( $returns['chart'][ $prefix.''.$row[$prefix.'id'] ] ) ) {
							$returns['chart'][ $prefix.''.$row[$prefix.'id'] ] = array();
						}

						if( !isset( $returns['chart'][ $prefix.''.$row[$prefix.'id'] ][ $i ] ) ) {
							$returns['chart'][ $prefix.''.$row[$prefix.'id'] ][ $i ] = 0;
						}

						$returns['chart'][ $prefix.''.$row[$prefix.'id'] ][ $i ] += (!empty($row[ $params['order_by'] ])) ? $row[ $params['order_by'] ] : 0;
					} 
				}
			}

			if( !empty($params['post']['cmd']) && $params['post']['cmd'] == 'top_prod_detailed' ) {
				$prod_abandoned_stats = sr_get_abandoned_products( array( 'cp_start_date' => $params['cumm_dates']['cp_start_date'],
														 'cp_end_date' => $params['cumm_dates']['cp_end_date'],
														 'security' => $params['security'],
														 'limit' => '',
														 'pid_cond' => $tp_ids) );

				if( !empty($prod_abandoned_stats) && !empty($prod_abandoned_stats['a_prod']) ) {
					foreach ($prod_abandoned_stats['a_prod'] as $key => $data) {
						$returns['kpi']['tpd_'.$data['id']]['arate'] = $data['arate'];
						$returns['kpi']['tpd_'.$data['id']]['aqty'] = $data['aqty'];
						$returns['kpi']['tpd_'.$data['id']]['added_to_cart'] = $data['added_to_cart'];
					}	
				}

				//  Query to get the last few orders for top products
				$results = array();

				$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
		     	$items_table_name = "{$wpdb->prefix}woo_sr_order_items";
				if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name 
					&& $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {

					$query = $wpdb->prepare ("SELECT CASE WHEN sroi.variation_id > 0 THEN sroi.variation_id
														ELSE sroi.product_id
														END AS tpd_id,
													SUBSTRING_INDEX(GROUP_CONCAT(sro.order_id ORDER BY sro.created_date SEPARATOR ','), ',', 5) as oids
											FROM {$wpdb->prefix}woo_sr_order_items sroi
												JOIN {$wpdb->prefix}woo_sr_orders sro
													ON (sro.order_id = sroi.order_id)
											WHERE sro.created_date BETWEEN '%s' AND '%s'
												AND sroi.order_is_sale = 1
												AND (sroi.type = 'S' OR sroi.type = 'R')
												AND sro.trash = 0
												".$prod_cond."
											GROUP BY tpd_id", $params['cumm_dates']['cp_start_date'], $params['cumm_dates']['cp_end_date']);

					$results = $wpdb->get_results($query, 'ARRAY_A');
				}

				if( count($results) > 0 ) {

					$order_ids = array();

					foreach ($results as $result) {

						if( empty($result['tpd_id']) || empty($result['oids']) || empty($returns['kpi']['tpd_'.$result['tpd_id']]) ) {
							continue;
						}

						if( empty($returns['kpi'][$result['tpd_id']]) ) {
							$returns['kpi']['tpd_'.$result['tpd_id']]['recent_orders'] = array();
						}

						$oids = explode(",",$result['oids']);

						$order_ids = array_merge($order_ids, $oids);

						foreach ($oids as $oid) {
							$returns['kpi']['tpd_'.$result['tpd_id']]['recent_orders'][$oid] = array();
						}
					}

					//  Query to get the details for the last few orders

					$result_details = array();

					$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
					if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {
						$query = "SELECT order_id as oid,
										created_date as odate,
										customer_name as cust_nm,
										billing_country as country,
										total as total
								FROM {$wpdb->prefix}woo_sr_orders
								WHERE trash = 0
									AND order_id IN (". implode(",",$order_ids) .")
								GROUP BY order_id";

						$result_details = $wpdb->get_results($query, 'ARRAY_A');
					}

					if( count($result_details) > 0 ) {

						$order_details = $countries = array();

						if ( (!empty($params['post']['params']['is_woo22']) && $params['post']['params']['is_woo22'] == "true") 
							|| (!empty($params['post']['params']['is_woo30']) && $params['post']['params']['is_woo30'] == "true") ) {
							$countries = WC()->countries->get_countries();
						} else if ( (!empty($params['post']['params']['is_woo22'] ) && $params['post']['params']['is_woo22'] == "false") 
									&& (!empty($params['post']['params']['is_woo30'] ) && $params['post']['params']['is_woo30'] == "false") ) {
							global $woocommerce;
							$countries = $woocommerce->countries->get_countries();
						}

						foreach ($result_details as $detail) {
							$order_details[$detail['oid']] = array('date' => $detail['odate'],
																	'cust_nm' => $detail['cust_nm'],
																	'country_code' => $detail['country'],
																	'country_nm' => (!empty($detail['country']) ? html_entity_decode($countries[ $detail['country'] ]) : ''),
																	'total' => $detail['total']);
						}

						foreach ($returns['kpi'] as &$data) {
							foreach ($data['recent_orders'] as $key => $order_data) {
								$data['recent_orders'][$key] = $order_details[$key];
							}							
						}
					}
				}


				//query to get the SKU for the top products
				$query = "SELECT post_id as id, 
								meta_key,
								meta_value
							FROM {$wpdb->prefix}postmeta
							WHERE meta_key IN ('_sku', '_thumbnail_id')
								AND post_id IN (". implode(",", $tp_ids) .")
							GROUP BY post_id, meta_key";

				$results = $wpdb->get_results($query, 'ARRAY_A');

				if( count($results) > 0 ) {
					foreach ($results as $result) {
						if( $result['meta_key'] == '_sku' ) {
							$returns['kpi']['tpd_'.$result['id']]['sku'] = $result['meta_value'];	
						} else if( $result['meta_key'] == '_thumbnail_id' ) {
								
							if ( !empty($result['meta_value']) ) {
								$image = wp_get_attachment_thumb_url( $result['meta_value'] );
							} 

							$image = str_replace( ' ', '%20', $image );
							$returns['kpi']['tpd_'.$result['id']]['thumb_url'] = '<img src="' . esc_url( $image ) . '" alt="' . esc_attr__( 'Thumbnail', $sr_text_domain ) . '" class="wp-post-image" height="48" width="48" />';
						}
					}
				}

				//query to get the categories for the top products
				if( !empty($returns['t_p_ids']) ) {
					$query = "SELECT tr.object_id as id,
								GROUP_CONCAT(t.name SEPARATOR ', ') AS cat
                            FROM {$wpdb->prefix}term_relationships as tr
                            	JOIN {$wpdb->prefix}term_taxonomy as tt ON (tt.term_taxonomy_id = tr.term_taxonomy_id)
                            	JOIN {$wpdb->prefix}terms as t ON (t.term_id = tt.term_id)
                            WHERE tt.taxonomy = 'product_cat'
                                   AND tr.object_id IN (". implode(",",$returns['t_p_ids']) .")
                            GROUP BY id";
			        $results = $wpdb->get_results ( $query, 'ARRAY_A' );

			        if(count($results) > 0) {
			        	foreach ($results as $result) {

			        		if( !empty($returns['kpi']['tpd_'.$result['id']]) ) {
			        			$returns['kpi']['tpd_'.$result['id']]['category'] = $result['cat'];
			        		} else {
			        			$child_ids = array_keys($returns['t_v_ids'], $result['id']);

			        			if( !empty($child_ids) ) {
			        				foreach ($child_ids as $child_id) {
			        					if( !empty($returns['kpi']['tpd_'.$child_id]) ) {
						        			$returns['kpi']['tpd_'.$child_id]['category'] = $result['cat'];
						        		}
			        				}
			        			}
			        		}
			        	}
			        }	
				}
			}
		}

		return $returns;
	}

	//formatting top prod keys
	function format_top_prod_keys(&$value, $key, $prefix) { 
		$value = $prefix. $value;
	}

	//Cummulative sales Query function
	function sr_query_sales($cumm_dates,$date_series,$post) {

			$params = !empty($post['params']) ? $post['params'] : array();

			if ( ! wp_verify_nonce( $params['security'], 'smart-reporter-security' ) ) {
	     		die( 'Security check' );
	     	}

		    global $wpdb, $sr_text_domain;

		    $returns = array();
		   
		   	// Initialize the return data
		    $returns['chart'] = $returns['kpi'] = array();
		    $returns['meta'] = array('start_date' => $cumm_dates['cp_start_date'],
		    							'end_date' => $cumm_dates['cp_end_date'],
		    							's_link' => admin_url().'edit.php?post_type=shop_order&source=sr&sdate='.$cumm_dates['cp_start_date'].'&edate='.$cumm_dates['cp_end_date']);

		    $returns['chart']['period'] = $date_series;
			$periods_count = count($returns['chart']['period']);
			$p2i = array_flip($returns['chart']['period']);

			$time_str = ( $cumm_dates['format'] == '%H' ) ? ':00:00' : '';


			if( !empty($_POST['cmd']) && $_POST['cmd'] == 'top_prod_detailed' ) { 

				$date_col = ( $cumm_dates['format'] == '%H' ) ? 'order_time' : 'order_date';

				$tp_params = array( 'order_by' => 'sales',
									 'date_col' => $date_col,
									 'time_str' => $time_str,
									 'limit'	=> 'LIMIT 50',
									 'p2i' 		=> $p2i,
									 'periods_count' => $periods_count,
									 'cumm_dates' => $cumm_dates,
									 'security' => $params['security'],
									 'post' 	=> $post
									);

				$tp_sales = sr_get_top_prod_data( $tp_params );

				$returns['kpi']['top_prod_detailed']['sales'] = $tp_sales['kpi'];
				$returns['chart'] = array_merge($returns['chart'], $tp_sales['chart']);

				$t_p_ids = $tp_sales['t_p_ids'];
				$t_v_ids = $tp_sales['t_v_ids'];

				// Code for getting the product title
				sr_get_prod_title(array( &$returns['kpi']['top_prod_detailed']['sales'] ), 
										array('t_p_ids' => $t_p_ids, 't_v_ids' => $t_v_ids, 'security' => $params['security']) );

				return json_encode($returns);
			}

			if( !empty($post['cmd']) && ($post['cmd'] == 'cumm_sales' || $post['cmd'] == 'sr_summary' ) ) {
				
				$date_col = ( $cumm_dates['format'] == '%H' ) ? 'created_time' : 'created_date';
				$chart_keys = array('sales', 'orders', 'discount');
				$payment_methods = $shipping_methods = array();

				// For each payment and shipping method...
				foreach( (array) WC_Payment_Gateways::instance()->get_available_payment_gateways() as $key => $value) {
					$chart_keys[] = 'pm_'.$key.'_sales';
					$chart_keys[] = 'pm_'.$key.'_orders';
					$returns['kpi']['pm'][$key] = array('title' => __($value->get_title(), $sr_text_domain), 
														'sales' => 0, 
														'orders' => 0,
														's_link' => '&s='.$value->get_title().'&s_col=payment_method&s_val='.$key);
				}
				foreach( (array) WC_Shipping::instance()->get_shipping_methods() as $key => $value) {
					$chart_keys[] = 'sm_'.$key.'_sales';
					$chart_keys[] = 'sm_'.$key.'_orders';
					$title = (isset($value->method_title)) ? $value->method_title : $value->get_title();
					$returns['kpi']['sm'][$key] = array('title' => __($title, $sr_text_domain), 
														'sales' => 0, 
														'orders' => 0, 
														's_link' => '&s='.$title.'&s_col=shipping_method&s_val='.$key);
				}

				// Initialize chart data to 0
				foreach ($chart_keys as $value) {
					$returns['chart'][$value] = array_fill(0, $periods_count, 0);
				}

				// KPIs are single item stats.. init for current and last period (lp_)
				$kpis = array('sales', 'refunds', 'orders', 'qty',
						'discount', 'tax', 'shipping', 'shipping_tax');
				foreach ($kpis as $value) {
					$returns['kpi'][$value] = 0;
					$returns['kpi']['lp_'.$value] = 0;
				}

				// Bring in grouped results for sales, discounts etc - then loop and process
				// LAST_PERIOD is special 'period' value for comparing current period data
				// with previous period

				$results = array();

				$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
				if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {

			    	$query = $wpdb->prepare ("SELECT 'LAST_PERIOD' as period, 
												SUM( CASE WHEN type = 'shop_order' AND status != 'wc-refunded' THEN 1 ELSE 0 END ) as orders, 
											IFNULL(SUM( CASE WHEN status != 'wc-refunded' THEN total END), 0) AS sales,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN total 
														WHEN type = 'shop_order_refund' THEN -1*total 
														ELSE 0 END), 0) AS refunds,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE qty END), 0) AS qty,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE discount+cart_discount END), 0) AS discount,
											0 AS tax,
											0 AS shipping,
											0 AS shipping_tax,
										    '' AS payment_method, 
										    '' AS shipping_method 
										FROM `{$wpdb->prefix}woo_sr_orders` 
										WHERE 
											created_date BETWEEN '%s' AND '%s'
											AND status in ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
											AND trash = 0
										UNION
										SELECT concat(DATE_FORMAT(".$date_col.", '%s'), '".$time_str."') AS period,
												SUM( CASE WHEN type = 'shop_order' AND status != 'wc-refunded' THEN 1 ELSE 0 END ) as orders, 
											IFNULL(SUM( CASE WHEN status != 'wc-refunded' THEN total END), 0) AS sales,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN total 
														WHEN type = 'shop_order_refund' THEN -1*total 
														ELSE 0 END), 0) AS refunds,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE qty END), 0) AS qty,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE discount+cart_discount END), 0) AS discount,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE tax END), 0) AS tax,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE shipping END), 0) AS shipping,
											IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN 0 
														ELSE shipping_tax END), 0) AS shipping_tax,
										    payment_method, 
										    shipping_method   
										FROM `{$wpdb->prefix}woo_sr_orders` 
										WHERE 
											created_date BETWEEN '%s' AND '%s'
											AND status in ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded') 
											AND trash = 0 
										GROUP BY period, payment_method, shipping_method", $cumm_dates['lp_start_date'], $cumm_dates['lp_end_date'], $cumm_dates['format'],
																							$cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);


			$results = $wpdb->get_results($query, 'ARRAY_A');

		}

		if( count($results) > 0){

			// The first row will always be last period data
			$row = array_shift($results);

			foreach ($kpis as $value) {
				$returns['kpi']['lp_'.$value] = $row[ $value ];
			}

			// Loop and total up values now
			foreach ($results as $row) {

				if (!array_key_exists($row['period'], $p2i) ) {
					error_log('Smart Reporter: Invalid value for "period" in DB results - '.$row['period']);
					continue;
				}

				// Total up sales, refunds, qty etc...
				foreach ($kpis as $key) {
					$returns['kpi'][$key] += $row[$key];
				}

				// Index of this period - this will be used to position different chart data at this period's index
				$i = $p2i[ $row['period'] ];

				// Set values in charts - for data other than payment / shipping methods
				foreach ($chart_keys as $key) {
					if (substr($key, 1, 2) != 'm_') {		// will match pm_ and sm_ both in single condition
						$returns['chart'][ $key ][ $i ] += $row[ $key ];
					}
				}

				// Set values for shipping and payment methods
				foreach (array('pm' => $row['payment_method'], 'sm' => $row['shipping_method']) as $type => $method) {

					if( $type == 'sm' ) {

						$actual_method = $method;
						$method = current( explode( ':', $method ) );
						if( !empty($method) ) {
							$returns['kpi']['sm'][$method]['s_link'] = substr($returns['kpi']['sm'][$method]['s_link'], 0, strpos($returns['kpi']['sm'][$method]['s_link'], "&s_val=")) .'&s_val='. $actual_method;
						}
					}

					foreach (array('sales', 'orders') as $f) {
						$key = $type . '_'. $method . '_'. $f;
						if (array_key_exists($key, $returns['chart'])) {

							$row[ $f ] = (( $type == 'sm' && $f == 'sales' ) ? $row[ 'shipping' ] : $row[ $f ]);

							$returns['chart'][ $key ][ $i ] += $row[ $f ];
							$returns['kpi'][$type][$method][$f] += $row[ $f ];
						}
					}
				}
		    }

		    // sorting the pm and sm by sales
		    $returns['kpi']['pm'] = array_slice(sr_multidimensional_array_sort($returns['kpi']['pm'], 'sales', 'DESC'),0,5);
		    $returns['kpi']['sm'] = array_slice(sr_multidimensional_array_sort($returns['kpi']['sm'], 'sales', 'DESC'),0,5);

		  }

		}

		if( !empty($post['cmd']) && ($post['cmd'] == 'cumm_cust_prod' || $post['cmd'] == 'sr_summary' ) ) {

			$chart_keys = array();
			$date_col = ( $cumm_dates['format'] == '%H' ) ? 'order_time' : 'order_date';

			// KPIs are single item stats.. init for current and last period (lp_)
			$kpis = array('car', 'carts', 'carts_prod', 'orders', 'orders_prod', 
							'corders', 'corders_prod', 'aipc', 'swc');
			foreach ($kpis as $value) {
				$returns['kpi'][$value] = 0;
				if ( $value == 'car' || $value == 'aipc' || $value == 'swc' ) {
					$returns['kpi']['lp_'.$value] = 0;	
				}
			}


			// ###############################
			// Top Customers
			// ###############################

			$returns['kpi']['top_cust'] = array();

			$results = array();

			$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
			if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {

				$query = $wpdb->prepare ("SELECT MAX(customer_name) as name,
											MAX(billing_email) as email, 
											CASE WHEN user_id > 0 THEN user_id ELSE billing_email END as user,  
											IFNULL(SUM(total), 0) AS sales 
										FROM {$wpdb->prefix}woo_sr_orders 
										WHERE created_date BETWEEN '%s' AND '%s'
											AND status in ('wc-completed', 'wc-processing', 'wc-on-hold') 
											AND trash = 0
										GROUP BY user 
										ORDER BY sales DESC
										LIMIT 5", $cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);

				$results = $wpdb->get_results($query, 'ARRAY_A');
			}

			if ( count($results) > 0 ) {

				foreach ( $results as $row ) {
					$returns['kpi']['top_cust'][] = array( 'name' => $row['name'],
															'email' => $row['email'],
															'sales' => $row['sales'],
															's_link' => '&s='.$row['email']. (($row['user'] > 0) ? '&s_col=user_id&s_val='.$row['user'] : '&s_col=billing_email&s_val='.$row['email']) );


				}
			}

			// ###############################
			// Billing Countries
			// ###############################

			$results = array();

			$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
			if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {

				$query = $wpdb->prepare ("SELECT SUM( CASE WHEN type = 'shop_order' THEN 1 ELSE 0 END ) as orders, 
											IFNULL(SUM(total), 0) AS sales,
											billing_country 
										FROM {$wpdb->prefix}woo_sr_orders 
										WHERE created_date BETWEEN '%s' AND '%s'
											AND status in ('wc-completed', 'wc-processing', 'wc-on-hold') 
											AND trash = 0
										GROUP BY billing_country", $cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);

				$results = $wpdb->get_results($query, 'ARRAY_A'); 
			}

			if ( count($results) > 0 ) {

				$returns['kpi']['billing_country'] = array();
				$returns['kpi']['billing_country']['sales'] = $returns['kpi']['billing_country']['orders'] = array();

				foreach ( $results as $row ) {

					if (empty($row['billing_country'])) {
						continue;
					}

					$returns['kpi']['billing_country']['sales'][$row['billing_country']] = $row['sales'];
					$returns['kpi']['billing_country']['orders'][$row['billing_country']] = $row['orders'];
				}
			}

			// ###############################
			// Top Products
			// ###############################

			// Confirm the handling of the partial refunds

			$t_p_ids = $t_v_ids = array();
			$returns['kpi']['top_prod'] = array( 'sales' => array(), 'qty' => array() );
			$tp_results = array();

			$limit = ( !empty($post['cmd']) && $post['cmd'] == 'top_prod_detailed' ) ? 'LIMIT 50' : 'LIMIT 5';

			$tp_params = array( 'order_by' => 'sales',
							 'date_col' => $date_col,
							 'time_str' => $time_str,
							 'limit'	=> 'LIMIT 5',
							 'p2i' 		=> $p2i,
							 'periods_count' => $periods_count,
							 'cumm_dates' => $cumm_dates,
							 'security' => $params['security']
							);

			$tp_sales = sr_get_top_prod_data( $tp_params );
			$returns['kpi']['top_prod']['sales'] = $tp_sales['kpi'];
			$returns['chart'] = array_merge($returns['chart'], $tp_sales['chart']);

			$t_p_ids = $tp_sales['t_p_ids'];
			$t_v_ids = $tp_sales['t_v_ids'];

			$tp_params = array( 'order_by' => 'qty',
							 'date_col' => $date_col,
							 'time_str' => $time_str,
							 'limit'	=> 'LIMIT 5',
							 'p2i' 		=> $p2i,
							 'periods_count' => $periods_count,
							 'cumm_dates' => $cumm_dates,
							 'security' => $params['security']
							);

			$tp_sales = sr_get_top_prod_data( $tp_params );
			$returns['kpi']['top_prod']['qty'] = $tp_sales['kpi'];
			$returns['chart'] = $returns['chart'] + $tp_sales['chart'];

			$t_p_ids = $t_p_ids + $tp_sales['t_p_ids'];
			$t_v_ids = $t_v_ids + $tp_sales['t_v_ids'];			

			// ###############################
			// Cart Abandonment Rate
			// ###############################
			
			$compare_time = sr_get_compare_time();

			$results = array();

			$table_name = "{$wpdb->prefix}woo_sr_cart_items";
			if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
				$query = $wpdb->prepare ("SELECT CASE 
													WHEN last_update_time >= unix_timestamp('%s') THEN 'C' 
													WHEN last_update_time >= unix_timestamp('%s') THEN 'L' 
												END as period,
											count(distinct( CASE WHEN last_update_time > ". $compare_time ." AND cart_is_abandoned = 0 THEN concat('O#', cart_id) ELSE concat('C#', cart_id) END ) ) as count, 
											SUM(qty) as items, 
											cart_is_abandoned 
										FROM {$wpdb->prefix}woo_sr_cart_items
										WHERE (last_update_time between unix_timestamp('%s') AND unix_timestamp('%s')) 
											OR (last_update_time between unix_timestamp('%s') AND unix_timestamp('%s'))
										GROUP BY period, cart_is_abandoned", $cumm_dates['cp_start_date'], $cumm_dates['lp_start_date'], $cumm_dates['lp_start_date'], $cumm_dates['lp_end_date'].' 23:59:59',
																							$cumm_dates['cp_start_date'], $cumm_dates['cp_end_date'].' 23:59:59');

				$results = $wpdb->get_results($query, 'ARRAY_A');
			}

			if ( count($results) > 0 ) {

				$c_acarts = $l_acarts = $l_carts = 0;

				foreach ( $results as $row ) {
					if ( $row['period'] == 'C' ) {

						$c_acarts = ($row['cart_is_abandoned'] == 1) ? $row['count'] : 0;
						$returns['kpi']['carts'] += $row['count'];
						$returns['kpi']['carts_prod'] += $row['items'];

					} else {
						$l_acarts = ($row['cart_is_abandoned'] == 1) ? $row['count'] : 0;
						$l_carts += $row['count'];
					}
				}

				$returns['kpi']['car'] = (( !empty($returns['kpi']['carts']) ) ? ($c_acarts/$returns['kpi']['carts']) : $c_acarts) * 100;
				$returns['kpi']['lp_car'] = (( !empty($l_carts) ) ? ($l_acarts/$l_carts) : $l_acarts) * 100;
			}

			// ###############################
			// Top Abandoned Products
			// ###############################

			$returns['kpi']['top_aprod'] = array();

			// get the top abandoned products

			$r_aprod = sr_get_abandoned_products( array( 'cp_start_date' => $cumm_dates['cp_start_date'],
														 'cp_end_date' => $cumm_dates['cp_end_date'],
														 'security' => $params['security'],
														 'limit' => 'LIMIT 5') );

			$returns['kpi']['top_aprod'] = $r_aprod['a_prod'];

			if ( count($returns['kpi']['top_aprod']) > 0 ) {

				$tap_keys = array();

				// initializing the chart data
				foreach ( $returns['kpi']['top_aprod'] as $taprod ) {
					$returns['chart']['tapq_'.$taprod['id']] = array_fill(0, $periods_count, 0);
					$tap_keys[$taprod['id']] = '';
				}

				// code for getting the chart data
				if( $r_aprod['a_flag'] == true ) {

					$results = array();

					$table_name = "{$wpdb->prefix}woo_sr_cart_items";
					if ( $wpdb->get_var( "show tables like '$table_name'" ) == $table_name ) {
						$query = $wpdb->prepare ("SELECT product_id as id,
														SUM(qty) as aqty,
														DATE_FORMAT(FROM_UNIXTIME(last_update_time), '%s') AS period
												FROM {$wpdb->prefix}woo_sr_cart_items
												WHERE cart_is_abandoned = 1
													AND last_update_time between unix_timestamp('%s') and unix_timestamp('%s')
													AND product_id IN (". implode(",", array_keys($tap_keys)) .")
												GROUP BY period, id", $cumm_dates['format'], $cumm_dates['cp_start_date'], $cumm_dates['cp_end_date'].' 23:59:59');

						$results = $wpdb->get_results($query, 'ARRAY_A'); 
					}

					if ( count($results) > 0 ) {

						foreach ( $results as $row ) {
							if (!array_key_exists($row['period'], $p2i) ) {
								error_log('Smart Reporter: Invalid value for "period" in DB results - '.$row['period']);
								continue;
							}

							// Index of this period - this will be used to position different chart data at this period's index
							$i = $p2i[ $row['period'] ];

							// Set values in charts
							$returns['chart'][ 'tapq_'.$row['id'] ][ $i ] += $row[ 'aqty' ];
						}
					}
				}

				//get the variation ids

				if ( count(array_keys($tap_keys)) > 0 ) {
					$query = "SELECT id, post_parent
								FROM {$wpdb->prefix}posts
								WHERE post_parent > 0
									AND id IN (". implode(",",array_keys($tap_keys)) .")
								GROUP BY id";

					$results = $wpdb->get_results($query, 'ARRAY_A'); 

					if ( count($results) > 0 ) {
						foreach ( $results as $row ) {
							$t_v_ids [$row['id']] = $row['post_parent'];
							$t_p_ids [] = $row['post_parent'];
						}
						$t_p_ids = array_merge($t_p_ids, array_keys(array_diff_key($tap_keys, $t_v_ids )) );
					} else {
						$t_p_ids = array_merge($t_p_ids, array_keys($tap_keys));	
					}
				}
			}

			// Code for getting the product title
			sr_get_prod_title(array( &$returns['kpi']['top_prod']['sales'], &$returns['kpi']['top_prod']['qty'], &$returns['kpi']['top_aprod'] ), 
					array('t_p_ids' => $t_p_ids, 't_v_ids' => $t_v_ids, 'security' => $params['security']) );

			// ###############################
			// Top Coupons
			// ###############################

			$returns['kpi']['top_coupons'] = array();

			$results = array();

			$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
			if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {

				$query = $wpdb->prepare ("SELECT COUNT( oi.order_item_name ) AS count,
											SUM(oim.meta_value) AS amt,
											oi.order_item_name AS name
						                FROM {$wpdb->prefix}woo_sr_orders AS so
						                	JOIN {$wpdb->prefix}woocommerce_order_items as oi ON ( so.order_id = oi.order_id )
						                	JOIN {$wpdb->prefix}woocommerce_order_itemmeta as oim 
						                		ON (oi.order_item_id = oim.order_item_id 
						                				AND oim.meta_key = 'discount_amount' )
						                WHERE so.created_date BETWEEN '%s' AND '%s'
						                    AND so.status in ('wc-completed', 'wc-processing', 'wc-on-hold')
						                    AND so.trash = 0
						                    AND oi.order_item_type = 'coupon'
						                GROUP BY oi.order_item_name
						                ORDER BY count DESC, amt DESC
						                LIMIT 5", $cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);

		        $results    = $wpdb->get_results ( $query, 'ARRAY_A' );
		    }

		    if ( count($results) > 0 ) {
		    	foreach ( $results as $row ) {
		    		$returns['kpi']['top_coupons'][] = array( 'title' => $row['name'],
																'sales' => $row['amt'],
																'count' => $row['count'],
																's_link' => '&s='.$row['name'].'&s_col=order_item_name&s_val='.$row['name']);
		    	}
		    }

		    // ###############################
			// Sales Funnel
			// ###############################

		    $results = array();

		    $orders_table_name = "{$wpdb->prefix}woo_sr_orders";
		    $items_table_name = "{$wpdb->prefix}woo_sr_order_items";
			if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name
				&& $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {

			    $query = $wpdb->prepare ("SELECT IFNULL( COUNT( DISTINCT( CASE WHEN so.created_date >= '%s' THEN so.order_id END ) ), 0) AS orders,
			    							IFNULL( COUNT( DISTINCT( CASE WHEN so.created_date >= '%s' THEN so.order_id END ) ), 0) AS lp_orders,
											IFNULL( SUM( CASE WHEN so.created_date >= '%s' THEN soim.qty END ), 0) AS orders_prod,
											IFNULL( SUM( CASE WHEN so.created_date >= '%s' THEN soim.qty END ), 0) AS lp_orders_prod,
											IFNULL( COUNT( DISTINCT(CASE WHEN so.created_date >= '%s' AND so.status = 'wc-completed' THEN so.order_id END) ), 0) AS corders,
											IFNULL( SUM( CASE WHEN so.created_date >= '%s' AND so.status = 'wc-completed' THEN soim.qty END ), 0) AS corders_prod
						                FROM {$wpdb->prefix}woo_sr_orders AS so
						                	JOIN {$wpdb->prefix}woo_sr_order_items as soim
						                		ON (so.order_id = soim.order_id
					                				AND so.type = 'shop_order'
					                				AND so.trash = 0
					                				AND soim.order_is_sale = 1
					                				AND soim.type = 'S')
						                WHERE (so.created_date BETWEEN '%s' AND '%s'
					                        		OR so.created_date BETWEEN '%s' AND '%s')", $cumm_dates['cp_start_date'], $cumm_dates['lp_start_date'],
																								$cumm_dates['cp_start_date'], $cumm_dates['lp_start_date'],
					                        													$cumm_dates['cp_start_date'], $cumm_dates['cp_start_date'],
																								$cumm_dates['lp_start_date'], $cumm_dates['lp_end_date'],
																								$cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);

		        $results = $wpdb->get_results ( $query, 'ARRAY_A' );
		    }

	        $lp_orders = $lp_orders_prod = 0;

		    if ( count($results) > 0 ) {
		    	foreach ($kpis as $value) {
		    		$returns['kpi'][$value] = ( !empty($results[0][$value]) ) ? $results[0][$value] : $returns['kpi'][$value];
		    	}

		    	$lp_orders = $results[0]['lp_orders'];
		    	$lp_orders_prod = $results[0]['lp_orders_prod'];

		    }

		    // ###############################
			// Sales With Coupons
			// ###############################

		    $results = array();

		    $orders_table_name = "{$wpdb->prefix}woo_sr_orders";
			if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {
			
			    $query 	= $wpdb->prepare ("SELECT IFNULL( COUNT( DISTINCT( CASE WHEN so.created_date >= '%s' THEN so.order_id END ) ), 0) AS cp_co,
			    								IFNULL( COUNT( DISTINCT( CASE WHEN so.created_date >= '%s' THEN so.order_id END ) ), 0) AS lp_co
				    						FROM {$wpdb->prefix}woo_sr_orders AS so
					                        	JOIN {$wpdb->prefix}woocommerce_order_items as oi ON ( oi.order_id = so.order_id )
					                        WHERE (so.created_date BETWEEN '%s' AND '%s'
					                        		OR so.created_date BETWEEN '%s' AND '%s')
							                    AND so.status in ('wc-completed', 'wc-processing', 'wc-on-hold')
							                    AND so.trash = 0
							                    AND oi.order_item_type = 'coupon'", $cumm_dates['cp_start_date'], $cumm_dates['lp_start_date'], 
																					$cumm_dates['lp_start_date'], $cumm_dates['lp_end_date'],
																					$cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);
				
				$results = $wpdb->get_results ( $query, 'ARRAY_A' );

			}

		    if ( count($results) > 0 ) {
		    	$returns['kpi']['swc'] = ( !empty($returns['kpi']['orders']) ) ? ( $results[0]['cp_co']/$returns['kpi']['orders'] ) * 100 : 0;
		    	$returns['kpi']['lp_swc'] = ( !empty($lp_orders) ) ? ( $results[0]['lp_co']/$lp_orders ) * 100 : 0;
		    }

		    // ###############################
			// Total Customers
			// ###############################

		    $results = array();

		    $orders_table_name = "{$wpdb->prefix}woo_sr_orders";
			if ( $wpdb->get_var( "show tables like '$orders_table_name'" ) == $orders_table_name ) {

			    $query = $wpdb->prepare ("SELECT 
											IFNULL(count( distinct ( CASE WHEN user_id > 0 AND created_date >= '%s' THEN user_id END ) ), 0) AS cust,
											IFNULL(count( distinct ( CASE WHEN user_id > 0 AND created_date >= '%s' THEN user_id END ) ), 0) AS old_cust,
											IFNULL(count( distinct ( CASE WHEN user_id = 0 AND created_date >= '%s' THEN billing_email END ) ), 0) AS guests,
											IFNULL(count( distinct ( CASE WHEN user_id = 0 AND created_date >= '%s' THEN billing_email END ) ), 0) AS old_guests
										FROM {$wpdb->prefix}woo_sr_orders 
										WHERE (created_date BETWEEN '%s' AND '%s' 
												OR created_date BETWEEN '%s' AND '%s' )
											AND type = 'shop_order'
											AND trash = 0
											AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')", $cumm_dates['cp_start_date'], $cumm_dates['lp_start_date'], 
																											$cumm_dates['cp_start_date'], $cumm_dates['lp_start_date'],
																											$cumm_dates['lp_start_date'], $cumm_dates['lp_end_date'],
																											$cumm_dates['cp_start_date'], $cumm_dates['cp_end_date']);

				$results = $wpdb->get_results ( $query, 'ARRAY_A' );
			}

		    if ( count($results) > 0 ) {
		    	
		    	$cp_cust = $results[0]['cust'] + $results[0]['guests'];
		    	$lp_cust = $results[0]['old_cust'] + $results[0]['old_guests'];

		    	$returns['kpi']['aipc'] = ( !empty($cp_cust) ) ? ( $returns['kpi']['orders_prod']/$cp_cust ) : $cp_cust;
		    	$returns['kpi']['lp_aipc'] = ( !empty($lp_cust) ) ? ( $lp_orders_prod/$lp_cust ) : $lp_cust;
		    }
		}


		if( !empty($post['cmd']) && $post['cmd'] == 'aprod_export' ) {

			$aprod = sr_get_abandoned_products( array( 'cp_start_date' => $cumm_dates['cp_start_date'],
														 'cp_end_date' => $cumm_dates['cp_end_date'],
														 'security' => $params['security'],
														 'limit' => '') );

			//get the variation ids

			$ap_keys = array();

			if( count($aprod['a_prod']) > 0 ) {
				foreach ( $aprod['a_prod'] as $data ) {

					if( empty($data['id']) ) {
						continue;
					}

					$ap_keys[$data['id']] = '';
				}	
			}

			$t_p_ids = $t_v_ids = array();

			if ( count($ap_keys) > 0 ) {
				$query = "SELECT id, post_parent
							FROM {$wpdb->prefix}posts
							WHERE post_parent > 0
								AND id IN (". implode(",",array_keys($ap_keys)) .")
							GROUP BY id";

				$results = $wpdb->get_results($query, 'ARRAY_A'); 

				if ( count($results) > 0 ) {
					foreach ( $results as $row ) {
						$t_v_ids [$row['id']] = $row['post_parent'];
						$t_p_ids [] = $row['post_parent'];
					}

					$t_p_ids = array_merge($t_p_ids, array_keys(array_diff_key($ap_keys, $t_v_ids )) );	
				} else {
					$t_p_ids = array_merge($t_p_ids, array_keys($ap_keys));	
				}

				sr_get_prod_title(array( &$aprod['a_prod'] ), 
								array('t_p_ids' => $t_p_ids, 't_v_ids' => $t_v_ids, 'security' => $params['security']) );

				return json_encode($aprod['a_prod']);
			}
			

		}

		if( !empty($post['cmd']) && $post['cmd'] == 'sr_summary' ) {
			return json_encode( $returns['kpi'] );
		}

		return json_encode($returns);
	}

	// Function for getting the formatted sales frequency
	function sr_get_frequency_formatted($days) {

		// 1 hr = 0.0416 days 

		if ($days < 0.0416)
        {
            $duration=round((($days/ 0.0416) * 60),2) . 'min';
        }
        else if ($days < 1)
        {
            /**
             * In this we convert 1 day velocity to be based upon Hours.
             * So we get say, 0.5 days we multiply it by 24 and it becomes 12hrs.
             *
             * 1min = 0.0167 hrs.
             */
            $valueAsPerDuration = $days * 24;
            $remainderValue = floor((($valueAsPerDuration % 1) / 0.0167));
            $duration =  floor($valueAsPerDuration) . 'h';
            $duration .= ($remainderValue != 0) ? ' ' . round($remainderValue,0) . 'min' : '';
        }
        else if ($days < 7)
        {
            $valueAsPerDuration = $days;
            $remainderValue = round((($valueAsPerDuration % 1) * 24),0);
            $duration = floor($valueAsPerDuration) . 'd';
            $duration .= ($remainderValue != 0) ? ' ' . $remainderValue . 'h' : '';
        }
        else if ($days < 30)
        {
            $valueAsPerDuration = $days / 7;
            $remainderValue = round(($valueAsPerDuration % 7),0);
            $duration = floor($valueAsPerDuration) . 'w';
            $duration .= ($remainderValue != 0) ? ' ' . $remainderValue . 'd' : '';
        }
        else if ($days < 365)
        {
            $valueAsPerDuration = $days / 30;
            $remainderValue =  round(($valueAsPerDuration % 30),0);
            $duration = floor($valueAsPerDuration) . 'm';
            $duration .= ($remainderValue != 0) ? ' ' . $remainderValue . 'd' : '';
        }
        else if ($days > 365)
        {
            $valueAsPerDuration = $days / 365;
            $remainderValue = round(($valueAsPerDuration % 365),0);
            $additionalText = '';

            if ($remainderValue > 30)
            {
                $remainderValue = round(($remainderValue / 30),0);
                $additionalText = 'm';
            }
            else
            {
                $additionalText = 'd';
            }
            $duration = floor($valueAsPerDuration) . 'y';
            $duration .= ($remainderValue != 0) ? ' ' . $remainderValue . $additionalText : '';
        }

        return $duration;
	}

	//Formatting the kpi data

	function sr_get_daily_kpi_data_formatted($data) {

		if ( ! wp_verify_nonce( $data['sr_security'], 'smart-reporter-security' ) ) {
     		die( 'Security check' ); 
     	}

     	unset($data['sr_security']);

     	$const = array();

		$const['SR_CURRENCY_SYMBOL'] = defined('SR_CURRENCY_SYMBOL') ? SR_CURRENCY_SYMBOL : (!empty($_POST['SR_CURRENCY_SYMBOL']) ? $_POST['SR_CURRENCY_SYMBOL'] : '');
	    $const['SR_DECIMAL_PLACES']  = defined('SR_DECIMAL_PLACES') ? SR_DECIMAL_PLACES : (!empty($_POST['SR_DECIMAL_PLACES']) ? $_POST['SR_DECIMAL_PLACES'] : 2);
	    $const['SR_IMG_UP_GREEN'] = defined('SR_IMG_UP_GREEN') ? SR_IMG_UP_GREEN : (!empty($_POST['SR_IMG_UP_GREEN']) ? $_POST['SR_IMG_UP_GREEN'] : '');
	    $const['SR_IMG_UP_RED'] = defined('SR_IMG_UP_RED') ? SR_IMG_UP_RED : (!empty($_POST['SR_IMG_UP_RED']) ? $_POST['SR_IMG_UP_RED'] : '');
	    $const['SR_IMG_DOWN_RED'] = defined('SR_IMG_DOWN_RED') ? SR_IMG_DOWN_RED : (!empty($_POST['SR_IMG_DOWN_RED']) ? $_POST['SR_IMG_DOWN_RED'] : '');
	    $const['SR_IS_WOO22'] = defined('SR_IS_WOO22') ? SR_IS_WOO22 : (!empty($_POST['SR_IS_WOO22']) ? $_POST['SR_IS_WOO22'] : '');
	    $const['SR_IS_WOO30'] = defined('SR_IS_WOO30') ? SR_IS_WOO30 : (!empty($_POST['SR_IS_WOO30']) ? $_POST['SR_IS_WOO30'] : '');

	    $returns = array();

	    foreach ( $data as $kpi => $val ) {

	    	// code for calculating the cmp. value
	    	if ( !empty($val['params']['cmp_format']) && $val['params']['cmp_format'] == '$' ) {
				$diff = sr_number_format(abs(round(($val['c'] - $val['lp']),2)),$const ['SR_DECIMAL_PLACES']);	
			} else if ( !empty($val['params']['cmp_format']) && $val['params']['cmp_format'] == '%' ) {
				$diff = sr_number_format(( (!empty($val['lp']) && $val['lp'] != 0 ) ? abs(round(((($val['c'] - $val['lp'])/$val['lp']) * 100),2)) : round($val['c'],2)),$const ['SR_DECIMAL_PLACES']). '%';
			} else {
				$diff = '';
			}

			if ( $diff != 0 ) {
				if ( $val['lp'] < $val['c'] ) {
					if ( $kpi == "refund_today" || $kpi == "orders_to_fulfill" ) {
						$img = $const ['SR_IMG_UP_RED'];
					} else {
						$img = $const ['SR_IMG_UP_GREEN'];	
					}
				}
				else {
					if ( $kpi == "daily_refund"  || $kpi == "orders_to_fulfill" ) {
						$img = $const ['SR_IMG_UP_GREEN'];
					} else {
				    	$img = $const ['SR_IMG_DOWN_RED'];
				    }
				}    
			} else {
				$diff = "";
				$img = "";
			}

			if ( empty($val['params']['currency_show']) ) {

				if ( $kpi == 'one_sale_every' ) {
					$f_val = sr_get_frequency_formatted($val['c']);
				} else {
					$f_val = sr_number_format($val['c'],$const ['SR_DECIMAL_PLACES']);	
				}
			} else {
				$f_val = $const ['SR_CURRENCY_SYMBOL'] . sr_number_format($val['c'],$const ['SR_DECIMAL_PLACES']);
			}


	    	$returns[$kpi] = '<span class = "daily_widgets_price"> ' . $f_val .
								' <i class= "'. $img .'" ></i>' . 
                              '  <span class = "daily_widgets_comp_price">'. $diff .'</span> </span>';


			if ( $kpi == 'one_sale_every' ) {
				$returns[$kpi] = '<p class="daily_widgets_text "> '. $val['title'] .' </p>'. $returns[$kpi];
			} else {
				$returns[$kpi] .= '<p class="daily_widgets_text "> '. $val['title'] .' </p>';
			}
	    }

		return $returns;

	}

	//Daily Widgets Data 

	function sr_get_daily_kpi_data($security, $format = 'html', $date = '') {

		if ( ! wp_verify_nonce( $security, 'smart-reporter-security' ) ) {
     		die( 'Security check' ); 
     	}

		global $wpdb, $sr_text_domain;

		//chk if the SR db dump table exists or not
    	$orders_table_name = "{$wpdb->prefix}woo_sr_orders";
    	$items_table_name = "{$wpdb->prefix}woo_sr_order_items";
     	if( $wpdb->get_var("SHOW TABLES LIKE '$orders_table_name'") != $orders_table_name 
     		|| $wpdb->get_var("SHOW TABLES LIKE '$items_table_name'") != $items_table_name ) {
     		return '';
     	}

	    $dates = array();

		$dates['today'] 			= (!empty($date)) ? $date : current_time( 'Y-m-d' );
		$dates['yesterday']		 	= date('Y-m-d', strtotime($dates['today'] .' -1 day'));
		$dates['c_month_start'] 	= date("Y-m-d",mktime(0, 0, 0, date('m', strtotime($dates['today'])), 1, date('Y', strtotime($dates['today']))));
		$dates['c_month_days']		= date('t', mktime(0, 0, 0, date('m', strtotime($dates['today'])), 1, date('Y', strtotime($dates['today']))));
		$dates['lp_date'] 		  	= date('Y-m-d', strtotime($dates['today'] . ' -1 month'));
		$dates['lp_month_start']	= date("Y-m-d", mktime(0,0,0,date('m', strtotime($dates['lp_date'])),1,date('Y', strtotime($dates['lp_date']))));
		$dates['c_mins']	 		= round(((current_time( 'timestamp')-strtotime($dates['c_month_start'])) / 60), 2);

		$daily_widget_data = array();
		$daily_widget_keys = array('sales_today', 'new_customers_today', 'refund_today', 'orders_to_fulfill', 'month_to_date_sales', 'avg_sales/day',
								 'one_sale_every', 'forecasted_sales');

		foreach ( $daily_widget_keys as $key ) {
			$daily_widget_data [$key] = array();
			$daily_widget_data [$key] ['title'] = __(ucwords(str_replace('_', ' ', $key)), $sr_text_domain);
			$daily_widget_data [$key] ['c'] = 0;
			$daily_widget_data [$key] ['lp'] = 0;

			$daily_widget_data [$key] ['params'] = array();
			$daily_widget_data [$key] ['params'] ['currency_show'] = ( $key == 'new_customers_today' || $key == 'orders_to_fulfill' || $key == 'one_sale_every' ) ? false : true;
			$daily_widget_data [$key] ['params'] ['cmp_format'] = ($key == 'forecasted_sales') ? 'none' : (($key == 'avg_sales/day') ? '$' : '%' );
		}

		$daily_widget_data['sr_security'] = $security;

		// ==================================================================
		// Todays Sales, Refunds, MTD sales, Avg. Sales/Day, Forecasted Sales
		// ==================================================================

		$query = $wpdb->prepare( "SELECT CASE 
							WHEN created_date = %s THEN 'C'
							WHEN created_date = %s THEN 'L'
							WHEN created_date >= %s THEN 'CM' 
							WHEN created_date >= %s THEN 'LM' 
							END 
							 as period, 
							SUM( CASE WHEN type = 'shop_order' AND status != 'wc-refunded' THEN 1 ELSE 0 END ) as orders, 
						IFNULL(SUM( CASE WHEN status != 'wc-refunded' THEN total END), 0) AS sales, 
						IFNULL(SUM( CASE WHEN status = 'wc-refunded' THEN total 
									WHEN type = 'shop_order_refund' THEN -1*total 
									ELSE 0 END), 0) AS refunds 
					FROM `{$wpdb->prefix}woo_sr_orders`
					WHERE ( created_date BETWEEN %s AND %s OR 
						  created_date BETWEEN %s AND %s )
						AND status in ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded') 
						AND trash = 0
					GROUP BY created_date", $dates['today'], $dates['yesterday'], $dates['c_month_start'], $dates['lp_month_start'],
					$dates['lp_month_start'], $dates['lp_date'], $dates['c_month_start'], $dates['today'] );
		
		$results = $wpdb->get_results( $query , 'ARRAY_A');

		if ( count($results) > 0 ) {
			
			$curr_orders = $lp_orders = 0;

			foreach ( $results as $row ) {

				if (empty($row ['period'])) {
					continue;
				}
		

				if( $row['period'] == 'C' ){
					$daily_widget_data ['sales_today']['c'] = (!empty($row['sales'])) ? $row['sales'] : 0;
					$daily_widget_data ['refund_today']['c'] = (!empty($row['refunds'])) ? $row['refunds'] : 0;

					// for adding today's sales
					$daily_widget_data ['month_to_date_sales']['c'] += $daily_widget_data ['sales_today']['c'];
					$curr_orders += (!empty($row['orders'])) ? $row['orders'] : 0;

				} else if( $row['period'] == 'L' ){
					$daily_widget_data ['sales_today']['lp'] = (!empty($row['sales'])) ? $row['sales'] : 0;
					$daily_widget_data ['refund_today']['lp'] = (!empty($row['refunds'])) ? $row['refunds'] : 0;

					// for adding yesterday's sales
					if ( $dates['yesterday'] <= $dates['lp_date'] ) {
						$daily_widget_data ['month_to_date_sales']['lp'] += $daily_widget_data ['sales_today']['lp'];
						$lp_orders += (!empty($row['orders'])) ? $row['orders'] : 0;
					} else {
						$daily_widget_data ['month_to_date_sales']['c'] += $daily_widget_data ['sales_today']['lp'];
						$curr_orders += (!empty($row['orders'])) ? $row['orders'] : 0;
					}

				} else if( $row['period'] == 'CM' ){
					$daily_widget_data ['month_to_date_sales']['c'] += (!empty($row['sales'])) ? $row['sales'] : 0;
					$curr_orders += (!empty($row['orders'])) ? $row['orders'] : 0;
				} else if( $row['period'] == 'LM' ) {
					$daily_widget_data ['month_to_date_sales']['lp'] += (!empty($row['sales'])) ? $row['sales'] : 0;
					$lp_orders += (!empty($row['orders'])) ? $row['orders'] : 0;
				}
			}

			$daily_widget_data ['avg_sales/day']['c'] = round( ( ($daily_widget_data ['month_to_date_sales']['c'] / $dates['c_mins']) * 1440), 2);
			$daily_widget_data ['avg_sales/day']['lp'] = round(( ($daily_widget_data ['month_to_date_sales']['lp'] / $dates['c_mins']) * 1440 ), 2);

			$daily_widget_data ['forecasted_sales']['c'] = round($daily_widget_data ['avg_sales/day']['c'] * $dates['c_month_days'], 2);


			// Code for calculating the sales frequency
			$daily_widget_data ['one_sale_every']['c'] = (!empty($curr_orders)) ? round((($dates['c_mins'] / 1440) / $curr_orders), 2) : '0';
			$daily_widget_data ['one_sale_every']['lp'] = (!empty($lp_orders)) ? round((($dates['c_mins'] / 1440) / $lp_orders), 2) : '0';

		}

		// ================================================
		// Todays Customers
		// ================================================

		// Get minimum user id for people registered yesterday and today
		$query = $wpdb->prepare( "SELECT date(user_registered) as date, IFNULL(MIN(ID), -1) as min_user_id
								  FROM `$wpdb->users` 
								  WHERE user_registered BETWEEN '%s' AND '%s'
								  GROUP BY date(user_registered)", $dates['yesterday'], $dates['today']);

		$results = $wpdb->get_results($query, 'ARRAY_A');

		$c_cust_cond = $lp_cust_cond = '';

		if ( count($results) > 0 ) {
			foreach ( $results as $row ) {
				if($row['date'] == $dates['today']){
					$c_cust_cond = (!empty($row['min_user_id'])) ? " OR user_id >= ".$row['min_user_id'] : '';
				} 
				else{
					$lp_cust_cond = (!empty($row['min_user_id'])) ? " OR user_id >= ".$row['min_user_id'] : '';
				}
			}

		}
		

		// Get number of customers - guests are all considered new customers, but registered users need to have id greater than the min user id
		$query = $wpdb->prepare( "SELECT  created_date as date,
										COUNT( distinct( CASE WHEN user_id > 0 THEN user_id ELSE billing_email END ) ) as customers
									FROM `{$wpdb->prefix}woo_sr_orders`   
									WHERE ( (created_date = '%s' AND (user_id = 0 ". $c_cust_cond ."))
												OR (created_date = '%s' AND (user_id = 0 ". $lp_cust_cond .")) )
										AND status in ('wc-completed', 'wc-processing', 'wc-on-hold')
										AND trash = 0
										AND type = 'shop_order'
									GROUP BY created_date", $dates['today'], $dates['yesterday']);

		$results = $wpdb->get_results($query, 'ARRAY_A');

		if ( count($results) > 0 ) {	
			foreach ( $results as $row ) {
				if( $row['date'] == $dates['today'] ){
					$daily_widget_data ['new_customers_today']['c'] = (!empty($row['customers'])) ? $row['customers'] : 0;
				} 
				else{
					$daily_widget_data ['new_customers_today']['lp'] = (!empty($row['customers'])) ? $row['customers'] : 0;
				}
			}
		}


		// ================================================
		// Orders Unfulfillment
		// ================================================

		// get the shipping status
		// $query = $wpdb->prepare("SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = %s", 'woocommerce_calc_shipping');
		// $result = $wpdb->get_var ( $query );
		
		
		
		// if ( !empty($result) && $result != 'yes' ) {

			// get no of physical products
			$query = $wpdb->prepare("SELECT count(DISTINCT post_id ) as products 
			                         FROM {$wpdb->prefix}postmeta
			                         WHERE (meta_key = %s AND meta_value = 'no')
			                             OR (meta_key = %s AND meta_value = 'no')", '_downloadable', '_virtual' );

			$result = $wpdb->get_var ( $query );
			
			if ( !empty($result) && $result > 0 ) {

				// get no of order to fulfillment
				$query = $wpdb->prepare("SELECT o.created_date as date, count( distinct( o.order_id ) ) as orders 
											FROM {$wpdb->prefix}woo_sr_orders as o, {$wpdb->prefix}woo_sr_order_items as oi,  {$wpdb->prefix}postmeta as pm  
											WHERE ( o.status = 'wc-processing' AND o.created_date BETWEEN '%s' AND '%s' AND o.trash = 0 )
												AND ( o.order_id = oi.order_id )
												AND ((pm.meta_key = '_downloadable' and pm.meta_value = 'no') OR (pm.meta_key = '_virtual' and pm.meta_value = 'no')) 
												AND ( pm.post_id = oi.product_id OR pm.post_id = oi.variation_id ) 
											GROUP by o.created_date ", $dates['yesterday'], $dates['today']);

				$results = $wpdb->get_results($query, 'ARRAY_A');



				if ( count($results) > 0 ) {
					foreach ( $results as $row ) {
						if($row['date'] == $dates['today']){
							$daily_widget_data ['orders_to_fulfill']['c'] = (!empty($row['orders'])) ? $row['orders'] : 0;
						} 
						else{
							$daily_widget_data ['orders_to_fulfill']['lp'] = (!empty($row['orders'])) ? $row['orders'] : 0;
						}
					}
				}

			} else {
				$daily_widget_data ['orders_to_fulfill']['c'] = __('NA', $sr_text_domain);	
			}
		// } else {
		// 	$daily_widget_data ['orders_to_fulfill']['c'] = __('NA', $sr_text_domain);
		// }


		if ( !empty($format) && $format == 'html' ) {
			return json_encode (sr_get_daily_kpi_data_formatted($daily_widget_data));	
		} else {
			return json_encode ($daily_widget_data);
		}
		
	}

	// ================
	// code for Sr Beta //
	// ================

	function sr_get_cumm_stats(){

		$params = !empty($_POST['params']) ? $_POST['params'] : array();

		if ( ! wp_verify_nonce( $params['security'], 'smart-reporter-security' ) ) {
     		die( 'Security check' );
     	}

	    $cumm_dates = array();

	    $cumm_dates['cp_start_date'] 	= ( !empty($_POST['start_date']) ) ? $_POST['start_date'] : date('Y-m-d');
	    $cumm_dates['cp_end_date'] 		= ( !empty($_POST['end_date']) ) ? $_POST['end_date'] : date('Y-m-d');
	    $cumm_dates['cp_diff_dates'] 	= (strtotime($cumm_dates['cp_end_date']) - strtotime($cumm_dates['cp_start_date']))/(60*60*24);

	    if ($cumm_dates['cp_diff_dates'] > 0) {
	        $cumm_dates['lp_end_date'] = date('Y-m-d', strtotime($cumm_dates['cp_start_date'] .' -1 day'));
	        $cumm_dates['lp_start_date'] = date('Y-m-d', strtotime($cumm_dates['lp_end_date']) - ($cumm_dates['cp_diff_dates']*60*60*24));
	    }
	    else {
	        $cumm_dates['lp_end_date'] = $cumm_dates['lp_start_date'] = date('Y-m-d', strtotime($cumm_dates['cp_start_date'] .' -1 day'));
	    }

	    $cumm_dates['lp_diff_dates'] = (strtotime($cumm_dates['lp_end_date']) - strtotime($cumm_dates['lp_start_date']))/(60*60*24);

	    // ================================================================================================
	    // TODO: convert the jqplot code to chart.js
	    if ($cumm_dates['cp_diff_dates'] > 0 && $cumm_dates['cp_diff_dates'] <= 30) {
	        $encoded['tick_format'] = "%#d/%b/%Y";
	    }
	    else if ($cumm_dates['cp_diff_dates'] > 30 && $cumm_dates['cp_diff_dates'] <= 365) {
	        $encoded['tick_format'] = "%b";
	    }
	    else if ($cumm_dates['cp_diff_dates'] > 365) {
	        $encoded['tick_format'] = "%Y";
	    }
	    else {
	        $encoded['tick_format'] = "%H:%M:%S";
	    }
	    // ================================================================================================


	    if ($cumm_dates['cp_diff_dates'] > 0 && $cumm_dates['cp_diff_dates'] <= 30) {

	    	$cumm_dates ['format'] = '%Y-%m-%d';

	        $date = $cumm_dates['cp_start_date'];
	        $date_series[0] = $date;
	        for ($i = 1;$i<=$cumm_dates['cp_diff_dates'];$i++ ) {
	        		$date = date ("Y-m-d", strtotime($date .' +1 day'));
	                $date_series[] = $date;
	        }
	    }else if ($cumm_dates['cp_diff_dates'] > 30 && $cumm_dates['cp_diff_dates'] <= 365) {
	     
	    	$cumm_dates ['format'] = '%b';
	        $date_series = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');

	    }else if ($cumm_dates['cp_diff_dates'] > 365) {

	    	$cumm_dates ['format'] = '%Y';

	        $year_strt = substr($cumm_dates['cp_start_date'], 0,4);
	        $year_end = substr($cumm_dates['cp_end_date'], 0,4);

	        $year_tmp[0] = $year_strt;

	        for ($i = 1;$i<=($year_end - $year_strt);$i++ ) {
	             $year_tmp [$i] = $year_tmp [$i-1] + 1;          
	        }

	        for ($i = 0;$i<sizeof($year_tmp);$i++ ) {
	            $date_series[] = $year_tmp[$i];
	        }
	    }else {

	    	$cumm_dates ['format'] = '%H';

	    	$date = $cumm_dates['cp_start_date'];

	        $date_series[0] = "00:00:00";
	        for ($i = 1;$i<24;$i++ ) {
	            $date = date ("H:i:s", strtotime($date .' +1 hours'));
	            $date_series[$i] = $date;
	        }
	    }

	    if( !empty($_POST['cmd']) && ($_POST['cmd'] == 'sr_summary' || $_POST['cmd'] == 'aprod_export' ) ) {
			return sr_query_sales($cumm_dates,$date_series,$_POST);
		}

	    echo sr_query_sales($cumm_dates,$date_series,$_POST);
	    exit;
	}


	// ================
	// Sync Orders Data
	// ================

	function sr_data_sync() {

		global $wpdb;

		if ( !empty($_POST['part']) && $_POST['part'] == 1 ) {
			$slimit = 0;
		} else {
			$slimit = (($_POST['part']-1)*100);
		}

		if( !empty($_POST['view']) && $_POST['view'] == 'old' ) { //sync for the old view
			$insert_query = "REPLACE INTO {$wpdb->prefix}sr_woo_order_items 
	                            ( `product_id`, `order_id`, `order_date`, `order_status`, `product_name`, `sku`, `category`, `quantity`, `sales`, `discount` ) VALUES ";

	        $all_order_items = array();

			// WC's code to get all order items
	        if( defined('SR_IS_WOO16') && SR_IS_WOO16 == "true" ) {
	            $results = $wpdb->get_results ("
							                    SELECT meta.post_id AS order_id, meta.meta_value AS items 
							                    FROM {$wpdb->prefix}posts AS posts
								                    LEFT JOIN {$wpdb->prefix}postmeta AS meta ON posts.ID = meta.post_id
								                    LEFT JOIN {$wpdb->prefix}term_relationships AS rel ON posts.ID=rel.object_ID
								                    LEFT JOIN {$wpdb->prefix}term_taxonomy AS tax USING( term_taxonomy_id )
								                    LEFT JOIN {$wpdb->prefix}terms AS term USING( term_id )

							                    WHERE 	meta.meta_key 		= '_order_items'
								                    AND 	posts.post_type 	= 'shop_order'
								                    AND 	posts.post_status 	= 'publish'
								                    AND 	tax.taxonomy		= 'shop_order_status'
								                    AND		term.slug			IN ('completed', 'processing', 'on-hold')
								                LIMIT ". $slimit .", 100", 'ARRAY_A');

	            $num_rows = $wpdb->num_rows;

	            if ($num_rows > 0) {
	            	foreach ( $results as $result ) {
		                    $all_order_items[ $result['order_id'] ] = maybe_unserialize( $result['items'] ); 
		            }	
	            }
	                    
	        } else {

	        	$select_posts = 'SELECT posts.ID AS order_id,
	        					posts.post_date AS order_date,
	        					posts.post_status AS order_status';

	        	if( (defined('SR_IS_WOO22') && SR_IS_WOO22 == "true") || (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true") ) {

	        		// AND posts.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
	        		$query_orders = $select_posts ." FROM {$wpdb->prefix}posts AS posts
		                            WHERE 	posts.post_type = 'shop_order'";
	        		
	        	} else {

	        		// AND	term.slug	IN ('completed', 'processing', 'on-hold')
	        		$query_orders = $select_posts ." FROM {$wpdb->posts} AS posts
			                            LEFT JOIN {$wpdb->prefix}term_relationships AS rel ON posts.ID=rel.object_ID
			                            LEFT JOIN {$wpdb->prefix}term_taxonomy AS tax USING( term_taxonomy_id )
			                            LEFT JOIN {$wpdb->prefix}terms AS term USING( term_id )

		                            WHERE 	posts.post_type 	= 'shop_order'
			                            AND 	posts.post_status 	= 'publish'
			                            AND 	tax.taxonomy		= 'shop_order_status'";
	        	}

	        	$query_orders .= " LIMIT ". $slimit .", 100";

	        	$results = $wpdb->get_results ($query_orders,'ARRAY_A');
	        	$orders_num_rows = $wpdb->num_rows;
	        	
	        	if ( $orders_num_rows > 0 ) {

	        		$order_ids = $order_post_details = array();

	        		foreach ($results as $result) {

	        			$order_ids[] = $result['order_id'];

	        			$order_post_details [$result['order_id']] = array();
	        			$order_post_details [$result['order_id']] ['order_date'] = $result['order_date'];
	        			$order_post_details [$result['order_id']] ['order_status'] = $result['order_status'];
	        		}

	        		$order_id = implode( ", ", $order_ids);
		            $order_id = trim( $order_id );

	                $query_order_items = "SELECT order_items.order_item_id,
	                                            order_items.order_id    ,
	                                            order_items.order_item_name AS order_prod,
			                                    GROUP_CONCAT(order_itemmeta.meta_key
			                                    ORDER BY order_itemmeta.meta_id
			                                    SEPARATOR '###' ) AS meta_key,
			                                    GROUP_CONCAT(order_itemmeta.meta_value
			                                    ORDER BY order_itemmeta.meta_id
			                                    SEPARATOR '###' ) AS meta_value
	                                    FROM {$wpdb->prefix}woocommerce_order_items AS order_items
	                                    	LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_itemmeta
	                                    		ON (order_items.order_item_id = order_itemmeta.order_item_id)
	                                    WHERE order_items.order_id IN ($order_id)
	                                    GROUP BY order_items.order_item_id
	                                    ORDER BY FIND_IN_SET(order_items.order_id,'$order_id')";
	                                
	                $results  = $wpdb->get_results ( $query_order_items , 'ARRAY_A');          
	                $num_rows = $wpdb->num_rows;

	                // query to fetch sku of all prodcut's 
	                $query_sku = "SELECT post_id, meta_value
	                			  FROM {$wpdb->prefix}postmeta 
	                			  WHERE meta_key ='_sku' 
	                			  ORDER BY post_id ASC";
					
					$skus = $wpdb->get_results($query_sku, 'ARRAY_A');

	                // query to fetch category of all prodcut's
	                $query_catgry = "SELECT posts.ID AS product_id,
	                				 	terms.name as category 
	                				 FROM {$wpdb->prefix}posts AS posts
		                				 JOIN {$wpdb->prefix}term_relationships AS rel ON (posts.ID = rel.object_ID) 
		                				 JOIN {$wpdb->prefix}term_taxonomy AS tax ON (rel.term_taxonomy_id = tax.term_taxonomy_id) 
		                				 JOIN {$wpdb->prefix}terms AS terms ON (tax.term_taxonomy_id = terms.term_id) 
	                				 WHERE tax.taxonomy = 'product_cat' ";
					
					$category = $wpdb->get_results($query_catgry, 'ARRAY_A');
								
					$catgry_data = $sku_data = array();
										
					foreach($skus as $sku){ // to make post_id as index & sku as value
						
						if(!empty($sku['meta_value'])){

						$sku_data[$sku['post_id']] = $sku['meta_value'];

						}
					}
					
					foreach ($category as $cat) { // to make product_id as index & category as value
						
							$key = $cat['product_id'];
						if(array_key_exists($key, $catgry_data)){ //if sub category exists then assign category in (parent, sub) format. 
							
							$catgry_data[$cat['product_id']] .= ', '.$cat['category'];
						
						} else{
								$catgry_data[$cat['product_id']] = $cat['category'];
						}
					}

	                if ($num_rows > 0) {
	                	foreach ( $results as $result ) {
		                    $order_item_meta_values = explode('###', $result ['meta_value'] );
		                    $order_item_meta_key = explode('###', $result ['meta_key'] );
		                    if ( count( $order_item_meta_values ) != count( $order_item_meta_key ) )
		                        continue; 
		                    $order_item_meta_key_values = array_combine($order_item_meta_key, $order_item_meta_values);
		                    
		                    if( !empty( $order_item_meta_key_values['_product_id'] ) ){

		                    	$key = $order_item_meta_key_values['_product_id'];
		                   	
		                   	}

		                    if(array_key_exists($key, $sku_data)){ // if key exists then assign it's sku

		                    	$order_item_meta_key_values['sku'] = $sku_data[$key];
		                    }
		                    
		                    if(array_key_exists($key, $catgry_data)){ // if key exists then assign it's category

		                    	$order_item_meta_key_values['category'] = $catgry_data[$key];
		                    }
		                    
		                    if ( !empty($order_post_details [$result['order_id']]) ) {
		                    	$order_item_meta_key_values ['order_date'] = $order_post_details [$result['order_id']] ['order_date'];
		                    	$order_item_meta_key_values ['order_status'] = $order_post_details [$result['order_id']] ['order_status'];
		                    }	                    

		                    if ( empty( $all_order_items[ $result['order_id'] ] ) ) {
		                        $all_order_items[ $result['order_id'] ] = array();
		                    }
		                    $all_order_items[ $result['order_id'] ][] = $order_item_meta_key_values;
		                }	
	                }
	                
	            }

	        } //end if

		    $values = sr_items_to_values( $all_order_items );

		    if ( count( $values ) > 0 ) {
		        $insert_query .= implode( ',', $values );
		        $wpdb->query( $insert_query );
		    }

		    if ( !empty($_POST['sfinal']) && $_POST['sfinal'] == 1 ) {
		    	delete_option('sr_old_data_sync');
		    }

		} else { //sync for the new view
			// empty temp tables
			$wpdb->query("DELETE FROM {$wpdb->prefix}woo_sr_orders_meta_all");

			//Queries for inserting into temp table

			$wpdb->query("INSERT INTO {$wpdb->prefix}woo_sr_orders (order_id, created_date, created_time, status, type, parent_id)
						SELECT ID as order_id, DATE(post_date) as date, TIME(post_date) as time, post_status as status, post_type as type, post_parent as parent_id 
						FROM  {$wpdb->prefix}posts
						WHERE post_type in ('shop_order', 'shop_order_refund')
							AND post_status NOT IN ('trash', 'auto-draft', 'draft')
						LIMIT ". $slimit .", 100");

			$o_ids = $wpdb->get_col("SELECT order_id FROM {$wpdb->prefix}woo_sr_orders WHERE update_flag = 0");
			
			$v = '';

			foreach ( $o_ids as $id ) {

				if( empty($id) ) {
					continue;
				}

				$v .= "( ".$id.", '_billing_country'), ( ".$id.", '_billing_email'),( ".$id.", '_billing_first_name'),
							( ".$id.", '_billing_last_name'),( ".$id.", '_cart_discount'),( ".$id.", '_cart_discount_tax'),( ".$id.", '_customer_user'),( ".$id.", '_order_currency'),
							( ".$id.", '_order_discount'),( ".$id.", '_order_shipping'),( ".$id.", '_order_shipping_tax'),( ".$id.", '_order_tax'),( ".$id.", '_order_total'),
							( ".$id.", '_payment_method'), ";
			}

			if( !empty($v) ) {
				$wpdb->query("INSERT INTO {$wpdb->prefix}woo_sr_orders_meta_all VALUES ". substr($v, 0, (strlen($v)-2)));	
			}

			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_orders AS sro
						SET sro.meta_values = (SELECT GROUP_CONCAT( IFNULL(pm.meta_value,'-') ORDER BY temp.meta_key SEPARATOR ' #sr# ') AS meta_values
											FROM {$wpdb->prefix}woo_sr_orders_meta_all as temp 
												LEFT JOIN {$wpdb->prefix}postmeta AS pm ON (pm.meta_key = temp.meta_key AND pm.post_id = temp.post_id)
											WHERE temp.post_id = sro.order_id)
						WHERE sro.update_flag = 0");

			//Code for transposing the concated data
			// sro.cart_discount_tax = temp.cart_discount_tax,
			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_orders AS sro
							JOIN (SELECT sro1.order_id AS oid, 
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 1), ' #sr# ', -1) AS billing_country,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 2), ' #sr# ', -1) AS billing_email,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 3), ' #sr# ', -1) AS billing_first_name,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 4), ' #sr# ', -1) AS billing_last_name,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 5), ' #sr# ', -1) AS cart_discount,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 6), ' #sr# ', -1) AS cart_discount_tax,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 7), ' #sr# ', -1) AS customer_user,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 8), ' #sr# ', -1) AS order_currency,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 9), ' #sr# ', -1) AS order_discount,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 10), ' #sr# ', -1) AS order_shipping,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 11), ' #sr# ', -1) AS order_shipping_tax,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 12), ' #sr# ', -1) AS order_tax,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 13), ' #sr# ', -1) AS order_total,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sro1.meta_values, ' #sr# ', 14), ' #sr# ', -1) AS payment_method
									FROM {$wpdb->prefix}woo_sr_orders AS sro1) AS temp ON (temp.oid = sro.order_id)
							SET sro.billing_country = temp.billing_country,
								sro.billing_email = temp.billing_email,
								sro.customer_name = concat(temp.billing_first_name,' ',temp.billing_last_name),
								sro.cart_discount = temp.cart_discount,
								sro.user_id = temp.customer_user,
								sro.currency = temp.order_currency,
								sro.discount = temp.order_discount,
								sro.shipping = temp.order_shipping,
								sro.shipping_tax = temp.order_shipping_tax,
								sro.tax = temp.order_tax,
								sro.total = temp.order_total,
								sro.payment_method = temp.payment_method
							WHERE sro.update_flag = 0");

			// empty temp tables
			$wpdb->query("DELETE FROM {$wpdb->prefix}woo_sr_orders_meta_all");

			// Queries for order items

			$wpdb->query("INSERT INTO {$wpdb->prefix}woo_sr_order_items (order_item_id, order_id, type )
				SELECT woi.order_item_id, woi.order_id,
					CASE WHEN sro.type = 'shop_order_refund' THEN 'R' ELSE 'S' END as type
				FROM {$wpdb->prefix}woocommerce_order_items as woi
					JOIN {$wpdb->prefix}woo_sr_orders AS sro ON (sro.order_id = woi.order_id AND sro.update_flag = 0)
				WHERE woi.order_item_type = 'line_item'");

			$o_ids = $wpdb->get_col("SELECT order_item_id FROM {$wpdb->prefix}woo_sr_order_items WHERE update_flag = 0");

			$v = '';

			foreach ( $o_ids as $id ) {
				if( empty($id) ) {
					continue;
				}
				$v .= "( ".$id.", '_line_total'), ( ".$id.", '_product_id'),( ".$id.", '_qty'), ( ".$id.", '_variation_id'), ";
			}

			if( !empty($v) ) {
				$wpdb->query("INSERT INTO {$wpdb->prefix}woo_sr_orders_meta_all VALUES ". substr($v, 0, (strlen($v)-2)));
			}

			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_order_items AS sroi
						SET sroi.meta_values = (SELECT GROUP_CONCAT( IFNULL(woi.meta_value,'-') ORDER BY temp.meta_key SEPARATOR ' #sr# ') AS meta_values
											FROM {$wpdb->prefix}woo_sr_orders_meta_all as temp 
												LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS woi ON (woi.meta_key = temp.meta_key AND woi.order_item_id = temp.post_id)
											WHERE temp.post_id = sroi.order_item_id)
						WHERE sroi.update_flag = 0");

			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_order_items AS sroi
							JOIN (SELECT sroi1.order_item_id AS oid, 
										SUBSTRING_INDEX(SUBSTRING_INDEX(sroi1.meta_values, ' #sr# ', 1), ' #sr# ', -1) AS line_total,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sroi1.meta_values, ' #sr# ', 2), ' #sr# ', -1) AS product_id,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sroi1.meta_values, ' #sr# ', 3), ' #sr# ', -1) AS qty,
										SUBSTRING_INDEX(SUBSTRING_INDEX(sroi1.meta_values, ' #sr# ', 4), ' #sr# ', -1) AS variation_id
									FROM {$wpdb->prefix}woo_sr_order_items AS sroi1) AS temp ON (temp.oid = sroi.order_item_id)
							SET sroi.total = temp.line_total,
								sroi.product_id = temp.product_id,
								sroi.qty = temp.qty,
								sroi.variation_id = temp.variation_id
							WHERE sroi.update_flag = 0");

			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_order_items AS oi
							JOIN {$wpdb->prefix}woo_sr_orders AS o ON (o.order_id = oi.order_id AND o.update_flag = 0)
						SET oi.order_date = o.created_date, 
							oi.order_time = o.created_time, 
							oi.order_is_sale = (CASE 
												WHEN (o.type = 'shop_order' AND o.status IN ('wc-completed', 'wc-processing', 'wc-on-hold' )) THEN 1 ELSE 0 
												END)");

			//query for updating the shipping_method
			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_orders AS o
								JOIN {$wpdb->prefix}woocommerce_order_items AS wo 
									ON (o.order_id = wo.order_id AND wo.order_item_type = 'shipping')
								JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS woi 
									ON (wo.order_item_id = woi.order_item_id AND woi.meta_key = 'method_id')
							SET shipping_method = woi.meta_value");


			// empty temp tables
			$wpdb->query("DELETE FROM {$wpdb->prefix}woo_sr_orders_meta_all");

			// empty meta_values col
			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_order_items 
							SET meta_values = '',
								update_flag = 1
							WHERE update_flag = 0");

			// empty meta_values col
			$wpdb->query("UPDATE {$wpdb->prefix}woo_sr_orders 
							SET meta_values = '',
								update_flag = 1
							WHERE update_flag = 0");

			if ( !empty($_POST['sfinal']) && $_POST['sfinal'] == 1 ) {

				$wpdb->query("DROP TABLE {$wpdb->prefix}woo_sr_orders_meta_all");

				$wpdb->query("ALTER TABLE {$wpdb->prefix}woo_sr_orders DROP COLUMN meta_values, DROP COLUMN update_flag");
				$wpdb->query("ALTER TABLE {$wpdb->prefix}woo_sr_order_items DROP COLUMN meta_values, DROP COLUMN update_flag");

				delete_option('sr_data_sync');
			}
		}

		exit;
	}

//=================================
//OLD SR CODE
//=================================

	global $wpdb, $months, $cat_rev, $order_arr;

	$del = 3;
	$result  = array ();
	$encoded = array ();
	$months  = array ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
	$cat_rev = array ();

	if (isset ( $_GET ['start'] ))
		$offset = $_GET ['start'];
	else
		$offset = 0;

	if (isset ( $_GET ['limit'] ))
		$limit = $_GET ['limit'];

	// For pro version check if the required file exists
	// if (file_exists ( '../pro/sr-woo.php' )){
	// 	define ( 'SRPRO', true );
	// } else {
	// 	define ( 'SRPRO', false );
	// }

	if (!function_exists('sr_arr_init')) {
		function sr_arr_init($arr_start, $arr_end, $category = '') {
			global $cat_rev, $months, $order_arr;

			for($i = $arr_start; $i <= $arr_end; $i ++) {
				$key = ($category == 'month') ? $months [$i - 1] : $i;
				$cat_rev [$key] = 0;
			}
		}	
	}

	function get_grid_data( $select, $from, $where, $where_date, $group_by, $search_condn, $order_by ) {
		global $wpdb, $cat_rev, $months, $order_arr;
			
			$woo_default_image = WP_PLUGIN_URL . '/smart-reporter-for-wp-e-commerce/resources/themes/images/woo_default_image.png';
			
			$results = array();
			$num_rows = 0;

			$items_table_name = "{$wpdb->prefix}sr_woo_order_items";
			if ( $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {
				$query = "$select $from $where $where_date $group_by $search_condn $order_by ";
				$results 	= $wpdb->get_results ( $query, 'ARRAY_A' );
				$num_rows   = $wpdb->num_rows;
			}


			$no_records = $num_rows;

			if ($no_records == 0) {
				$encoded ['gridItems'] 		= '';
				$encoded ['gridTotalCount'] = '';
				$encoded ['msg']			= 'No records found';
			} else {
				$count = 0 ;
				$grid_data = array();
				$grid_data [$count] ['sales']    = '';
				$grid_data [$count] ['discount'] = '';
				$grid_data [$count] ['products'] = 'All Products';
				$grid_data [$count] ['period']   = 'selected period';
				$grid_data [$count] ['category'] = 'All Categories';
				$grid_data [$count] ['id'] 	     = '';
				$grid_data [$count] ['quantity'] = 0;
				$grid_data [$count] ['image'] = $woo_default_image;		//../wp-content/plugins/wp-e-commerce/wpsc-theme/wpsc-images/noimage.png


				//Code to get the thumnail_id

				$query_thumbnail_id = "SELECT postmeta.post_id AS id,
										   postmeta.meta_value AS thumbnail
									FROM {$wpdb->prefix}postmeta AS postmeta
										JOIN {$wpdb->prefix}posts AS posts ON (postmeta.post_id = posts.id AND postmeta.meta_key = '_thumbnail_id')
									WHERE posts.post_type IN ('product', 'product_variation')";
				$results_thumnail_id = $wpdb->get_results($query_thumbnail_id, 'ARRAY_A');
				$rows_thumbnail_id = $wpdb->num_rows;

				$prod_thumnail_ids = array();

				if ( $rows_thumbnail_id > 0 ) {
					foreach ( $results_thumnail_id as $result_thumnail_id ) {
						$prod_thumnail_ids [$result_thumnail_id['id']] = $result_thumnail_id['thumbnail'];
					}
				}

				foreach ( $results as $result ) {
					$grid_data [$count] ['quantity'] = $grid_data[$count] ['quantity'] + $result ['quantity'];
					$grid_data [$count] ['sales'] = $grid_data[$count] ['sales'] + $result ['sales'];
					$grid_data [$count] ['discount'] = $grid_data[$count] ['discount'] + $result ['discount'];
				}
				$count++;
				
				foreach ( $results as $result ) {
					$grid_data [$count] ['products'] = $result ['products'];
					$grid_data [$count] ['period']   = (!empty($result ['period'])) ? $result ['period'] : '';
					$grid_data [$count] ['sales']    = $result ['sales'];
					$grid_data [$count] ['discount'] = $result ['discount'];
					$grid_data [$count] ['category'] = $result ['category'];
					$grid_data [$count] ['id'] 	 	 = $result ['id'];
					$grid_data [$count] ['quantity'] = $result ['quantity'];
					// $thumbnail = isset( $result ['thumbnail'] ) ? wp_get_attachment_image_src( $result ['thumbnail'], 'admin-product-thumbnails' ) : '';
					$thumbnail = !empty( $prod_thumnail_ids [$result ['id']] ) ? wp_get_attachment_image_src( $prod_thumnail_ids [$result ['id']], 'admin-product-thumbnails' ) : '';
					$grid_data [$count] ['image']    = ( !empty($thumbnail[0]) && $thumbnail[0] != '' ) ? $thumbnail[0] : $woo_default_image;
					$count++;
				}
					
				$encoded ['gridItems']      = $grid_data;
				$encoded ['period_div'] 	= (!empty($parts ['category'])) ? $parts ['category'] : '';
				$encoded ['gridTotalCount'] = count($grid_data);
			}

		return $encoded;
	}

	function get_graph_data( $product_id, $where_date, $parts ) {
		global $wpdb, $cat_rev, $months, $order_arr;
		
        $cat_rev1 = array();
	
		$encoded = get_last_few_order_details( $product_id, $where_date );

                $time = '';
                if(isset($parts['day']) && $parts['day'] == 'today' ) {
                    $time = ",DATE_FORMAT(max(posts.`post_date`), '%H:%i:%s') AS time";
                    for ($i=0;$i<24;$i++) {
                        $cat_rev1[$i] = 1;
                    }
                }
                
		$select  = "SELECT SUM( order_item.sales ) AS sales,
					DATE_FORMAT(posts.`post_date`, '{$parts ['abbr']}') AS period
                                        $time    
				   ";
		
		$from = " FROM {$wpdb->prefix}sr_woo_order_items AS order_item
			  	  LEFT JOIN {$wpdb->prefix}posts AS posts ON ( posts.ID = order_item.order_id )
				";
		
		$where = ' WHERE 1 ';
		
		$group_by = " GROUP BY period";
	
		if ( isset ( $product_id ) && $product_id != 0 ) {
			$where 	   .= " AND order_item.product_id = $product_id ";
		}
		
		$results = array();
		$num_rows = 0;

		$items_table_name = "{$wpdb->prefix}sr_woo_order_items";
		if ( $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {
			$query = "$select $from $where $where_date $group_by";
			$results 	= $wpdb->get_results ( $query, 'ARRAY_A' );
			$num_rows   = $wpdb->num_rows;
		}


		$no_records = ($num_rows != 0) ? count ( $cat_rev ) : 0;

		if ($no_records != 0) {
			foreach ( $results as $result ) { // put within condition
				$cat_rev [$result['period']]  = $result ['sales'];
                if(isset($parts['day']) && $parts['day'] == 'today' ) {
                    $cat_rev1 [$result['period']]  = $result ['time'];
				}
            }

            $i = 0;
        	foreach ( $cat_rev as $mon => $rev ) {
				$record ['period'] = $mon;
				$record ['sales'] = $rev;
                                
                if(isset($parts['day']) && $parts['day'] == 'today' ) {
                    $record ['time'] = $cat_rev1[$i];
                }
				$records [] = $record;
                $i++;
			}
		}
		
		if ($no_records == 0) {
			$encoded ['graph'] ['items'] = '';
			$encoded ['graph'] ['totalCount'] = 0;
		} else {
			$encoded ['graph'] ['items'] = $records;
			$encoded ['graph'] ['totalCount'] = count($cat_rev);
		}
		
		return $encoded;
	}

	function get_last_few_order_details( $product_id, $where_date ) {
		global $wpdb, $cat_rev, $months, $order_arr;
			
			$select = "SELECT order_item.order_id AS order_id,
							  posts.post_date AS date,
							  GROUP_CONCAT( distinct postmeta.meta_value
									ORDER BY postmeta.meta_id 
									SEPARATOR ' ' ) AS cname,
							  ( SELECT post_meta.meta_value FROM {$wpdb->prefix}postmeta AS post_meta WHERE post_meta.post_id = order_item.order_id AND post_meta.meta_key = '_billing_country' ) AS country,
							  ( SELECT post_meta.meta_value FROM {$wpdb->prefix}postmeta AS post_meta WHERE post_meta.post_id = order_item.order_id AND post_meta.meta_key = '_order_total' ) AS totalprice
					  ";
			
			$from = " FROM {$wpdb->prefix}sr_woo_order_items AS order_item
				  	  LEFT JOIN {$wpdb->prefix}posts AS posts ON ( posts.ID = order_item.order_id AND posts.post_status IN ('wc-on-hold', 'wc-processing', 'wc-completed') )
				  	  LEFT JOIN {$wpdb->prefix}postmeta AS postmeta ON ( order_item.order_id = postmeta.post_id AND postmeta.meta_key IN ( '_billing_first_name', '_billing_last_name' ) )
					";
			
			$where = ' WHERE 1 ';
			
			$order_by = "ORDER BY date DESC";
			
			$limit = "limit 0,5";
			
			if ( isset( $product_id ) ) $group_by  = "GROUP BY order_id";
			
			if ( isset ( $product_id ) && $product_id != 0 ) {
				$where 	   .= " AND order_item.product_id = $product_id ";
			}
			
			$results = array();
			$no_records = 0;

			$items_table_name = "{$wpdb->prefix}sr_woo_order_items";
			if ( $wpdb->get_var( "show tables like '$items_table_name'" ) == $items_table_name ) {
				$query = "$select $from $where $where_date $group_by $order_by $limit";
				$results 	= $wpdb->get_results ( $query, 'ARRAY_A' );
				$num_rows   = $wpdb->num_rows;
				$no_records = $num_rows;
			}
				
			if ($no_records == 0) {
				$encoded ['orderDetails'] ['order'] 		= '';
				$encoded ['orderDetails'] ['orderTotalCount'] = 0;
			}  else {			
				$cnt = 0;
				$order_data = array();
				foreach ( $results as $result ) { // put within condition	
					$order_data [$cnt] ['purchaseid'] = $result ['order_id'];
					$order_data [$cnt] ['date']       = date( "d-M-Y",strtotime( $result ['date'] ) ); 

					if(!empty($_POST['detailed_view'])){ // for detailed view widget

						$order_data [$cnt] ['totalprice'] 	  = sprintf( $_POST['SR_CURRENCY_POS'] , $_POST['SR_CURRENCY_SYMBOL'] , sr_number_format($result ['totalprice'], $_POST['SR_DECIMAL_PLACES']) );
						$order_data [$cnt] ['country_code']	  = $result ['country'];

						if ( (!empty($_POST['SR_IS_WOO22']) && $_POST['SR_IS_WOO22'] == "true") || (!empty($_POST['SR_IS_WOO30']) && $_POST['SR_IS_WOO30'] == "true") ) {

							$countries = WC()->countries->get_countries();
						}
						elseif ( (!empty($_POST['SR_IS_WOO22'] ) && $_POST['SR_IS_WOO22'] == "false") && (!empty($_POST['SR_IS_WOO30'] ) && $_POST['SR_IS_WOO30'] == "false") ) {
							global $woocommerce;
							$countries = $woocommerce->countries->get_countries();
						}
						$order_data [$cnt] ['country_name'] = html_entity_decode($countries[ $result['country'] ]);
					
					} else{

					$order_data [$cnt] ['totalprice'] = (defined('SR_IS_WOO30') && SR_IS_WOO30 == "true" ) ? wc_price($result ['totalprice']) : woocommerce_price($result ['totalprice']);

					}

					$order_data [$cnt] ['cname']      = $result ['cname'];
					$orders [] = $order_data [$cnt];				
					$cnt++;
				}	
			
				$encoded ['orderDetails'] ['order'] = $orders;
				$encoded ['orderDetails'] ['orderTotalCount'] = count($orders);
			}
			
		return $encoded;
	}

	if (isset ( $_GET ['cmd'] ) && (($_GET ['cmd'] == 'getData') || ($_GET ['cmd'] == 'gridGetData'))) {

		check_ajax_referer('smart-reporter-security','security');

	        if ( defined('SRPRO') && SRPRO == true ) {
	            if ( SR_WPSC_RUNNING === true ) {
			if ( file_exists ( SR_PLUGIN_DIR_ABSPATH. '/pro/sr.php' ) ) include( SR_PLUGIN_DIR_ABSPATH. '/pro/sr.php' );
	            } else {
	                if ( file_exists ( SR_PLUGIN_DIR_ABSPATH. '/pro/sr-woo.php' ) ) include_once( SR_PLUGIN_DIR_ABSPATH. '/pro/sr-woo.php' );
	            }
	        }
	    
		if (isset ( $_GET ['fromDate'] )) {
			$from ['date'] = strtotime ( $_GET ['fromDate'] );
			$to ['date'] = strtotime ( $_GET ['toDate'] );
		 
			if ($to ['date'] == 0) {
				$to ['date'] = strtotime ( 'today' );
			}
			// move it forward till the end of day
			$to ['date'] += 86399;

			// Swap the two dates if to_date is less than from_date
			if ($to ['date'] < $from ['date']) {
				$temp = $to ['date'];
				$to ['date'] = $from ['date'];
				$from ['date'] = $temp;
			}
			// date('Y-m-d H:i:s',(int)strtotime($_POST ['fromDate']))		$from ['date']		$to['date']
			if ( defined('SRPRO') && SRPRO == true ){
				$where_date = " AND (posts.`post_date` between '" . date('Y-m-d H:i:s',$from ['date']) . "' AND '" . date('Y-m-d H:i:s',$to['date']) . "')";
			}else{
				$diff = 86400 * 7;
				if ( (( $from ['date'] - $to ['date'] ) <= $diff ) )
				$where_date = " AND (posts.`post_date` between '" . date('Y-m-d H:i:s',$from ['date']) . "' AND '" . date('Y-m-d H:i:s',$to['date']) . "')";
			}

			//BOF bar graph calc

			$frm ['yr'] = date ( "Y", $from ['date'] );
			$to ['yr'] = date ( "Y", $to ['date'] );

			$frm ['mon'] = date ( "n", $from ['date'] );
			$to ['mon'] = date ( "n", $to ['date'] );

			$frm ['week'] = date ( "W", $from ['date'] );
			$to ['week'] = date ( "W", $to ['date'] );

			$frm ['day'] = date ( "j", $from ['date'] );
			$to ['day'] = date ( "j", $to ['date'] );

			$parts ['category'] = '';
			$parts ['no'] = 0;

			if ($frm ['yr'] == $to ['yr']) {
				if ($frm ['mon'] == $to ['mon']) {

					if ($frm ['week'] == $to ['week']) {
						if ($frm ['day'] == $to ['day']) {
							$diff = $to ['date'] - $from ['date'];
							$parts ['category'] = 'hr';
							$parts ['no'] = 23;
							$parts ['abbr'] = '%k';
	                                                $parts ['day'] = 'today';

							sr_arr_init ( 0, $parts ['no'],'hr' );
						} else {
							$parts ['category'] = 'day';
							$parts ['no'] = date ( 't', $from ['date'] );
							$parts ['abbr'] = '%e';

							sr_arr_init ( 1, $parts ['no'] );
						}
					} else {
						$parts ['category'] = 'day';
						$parts ['no'] = date ( 't', $from ['date'] );
						$parts ['abbr'] = '%e';

						sr_arr_init ( 1, $parts ['no'] );
					}
				} else {
					$parts ['category'] = 'month';
					$parts ['no'] = $to ['mon'] - $frm ['mon'];
					$parts ['abbr'] = '%b';

					sr_arr_init ( $frm ['mon'], $to ['mon'], $parts ['category'] );
				}
			} else {
				$parts ['category'] = 'year';
				$parts ['no'] = $to ['yr'] - $frm ['yr'];
				$parts ['abbr'] = '%Y';

				sr_arr_init ( $frm ['yr'], $to ['yr'] );
			}
			// EOF
		}
		
		$static_select = "SELECT order_item.product_id AS id,
						 order_item.product_name AS products,
						 prod_categories.category AS category,
						 SUM( order_item.quantity ) AS quantity,
						 SUM( order_item.sales ) AS sales,
						 SUM( order_item.discount ) AS discount
						";
			
		$from = " FROM {$wpdb->prefix}sr_woo_order_items AS order_item
				  LEFT JOIN {$wpdb->prefix}posts AS products ON ( products.id = order_item.product_id )
				  LEFT JOIN ( SELECT GROUP_CONCAT(wt.name SEPARATOR ', ') AS category, wtr.object_id
						FROM  {$wpdb->prefix}term_relationships AS wtr  	 
						JOIN {$wpdb->prefix}term_taxonomy AS wtt ON (wtr.term_taxonomy_id = wtt.term_taxonomy_id and taxonomy = 'product_cat')
						JOIN {$wpdb->prefix}terms AS wt ON (wtt.term_id = wt.term_id)
						GROUP BY wtr.object_id) AS prod_categories on (products.id = prod_categories.object_id OR products.post_parent = prod_categories.object_id)
				  LEFT JOIN {$wpdb->prefix}posts as posts ON ( posts.ID = order_item.order_id )
				  ";
			
		$where = " WHERE products.post_type IN ('product', 'product_variation') ";
			
		$group_by = " GROUP BY order_item.product_id ";
			
		$order_by = " ORDER BY sales DESC ";
		
		$search_condn = '';

		if (isset ( $_GET ['searchText'] ) && $_GET ['searchText'] != '') {
			$search_on = $wpdb->_real_escape ( trim ( $_GET ['searchText'] ) );
			$search_ons = explode( ' ', $search_on );
			if ( is_array( $search_ons ) ) {	
				$search_condn = " HAVING ";
				foreach ( $search_ons as $search_on ) {
					$search_condn .= " order_item.product_name LIKE '%$search_on%' 
									   OR prod_categories.category LIKE '%$search_on%' 
									   OR order_item.product_id LIKE '%$search_on%'
									   OR";
				}
				$search_condn = substr( $search_condn, 0, -2 );
			} else {
				$search_condn = " HAVING order_item.product_name LIKE '%$search_on%' 
									   OR prod_categories.category LIKE '%$search_on%' 
									   OR order_item.product_id LIKE '%$search_on%'
							";
			}
			
		}
		
		if ($_GET ['cmd'] == 'gridGetData') {
			
			$encoded = get_grid_data( $static_select, $from, $where, $where_date, $group_by, $search_condn, $order_by );
			
		} else if ($_GET ['cmd'] == 'getData') {

			$encoded = get_graph_data( $_GET ['id'], $where_date, $parts );
			
		}
		while(ob_get_contents()) {
         	   ob_clean();
		}

		echo json_encode ( $encoded );
		unset($encoded);
    	exit;
	}

// ob_end_flush();

?>