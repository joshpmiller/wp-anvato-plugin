<?php

/**
 * Anvato service for Media Explorer.
 */
class MEXP_Anvato_Service extends MEXP_Service {

	/**
	 * The values of the Anvato plugin option on instantiation.
	 *
	 * @var array
	 */
	private $option_values;

	/**
	 * Constructor.
	 *
	 * Creates the Backbone view template.
	 */
	public function __construct() {
		$this->option_values = get_option(Anvato_Settings::general_settings_key);
		$this->set_template(new MEXP_Anvato_Template);
	}

	/**
	 * Fired when the Anvato service is loaded.
	 *
	 * Allows the service to enqueue JS/CSS only when it's required.
	 */
	public function load() {
		add_filter('mexp_tabs', array($this, 'tabs'), 10, 1);
		add_filter('mexp_labels', array($this, 'labels'), 10, 1);
		wp_enqueue_style('mexp-service-anvato', ANVATO_URL . 'mexp/style.css', array('mexp'), '0.1.5');
		wp_enqueue_script('mexp-service-anvato', ANVATO_URL . 'mexp/js.js', array('jquery', 'mexp'), '0.1.5');
	}

	/**
	 * Handles the AJAX request for videos and returns an appropriate response.
	 *
	 * @see  Anvato_Library->search() for documentation of request parameters
	 *     and returned video data.
	 *
	 * @param array $request The request parameters.
	 * @return MEXP_Response|bool|WP_Error A MEXP_Response object should be
	 *     returned on success, boolean false should be returned if there are no
	 *     results to show, and a WP_Error should be returned if there is an
	 *     error.
	 */
	public function request(array $request) {

		$params = array();
		if (!empty($request['params']['q'])) {
			$params['lk'] = sanitize_text_field($request['params']['q']);
		}
		
		switch ($request['params']['type']) {
			case "live":
				$callback = "generate_mexp_response_for_channel";
				break;
			case "vod":
			default:
				$callback = "generate_mexp_response_for_vod";
				break;
		}
		
		$params['type'] = $request['params']['type'];
		$results = Anvato_Library()->search($params);

		if (is_wp_error($results)) {
			return $results;
		} elseif (empty($results)) {
			return false;
		}

		$response = call_user_method($callback, $this, $results);

		return $response;
	}

	function generate_mexp_response_for_vod($results) 
	{
		$response = new MEXP_Response();
		foreach ($results as $video) {
			$item = new MEXP_Response_Item();
			$item->set_content(sanitize_text_field((string) $video->title));
			$description = implode(' ', array_slice(explode(" ", (string) $video->description), 0, 10))."...";
			$item->add_meta("description", sanitize_text_field($description));
			$item->add_meta("duration", sanitize_text_field((string) $video->duration));
			$item->add_meta("category", sanitize_text_field((string) $video->categories->primary_category));
			$item->set_date(strtotime(sanitize_text_field((string) $video->ts_added)));
			$item->set_date_format("M j, Y, g:i a");
			$item->set_id(intval((string) $video->upload_id));
			$item->set_thumbnail((string) $video->src_image_url);
			$item->url = $this->generate_shortcode((string) $video->upload_id);
			/**
			 * Filter the video item to be added to the response.
			 *
			 * @param  MEXP_Response_Item $item The response item.
			 * @param  SimpleXMLElement $video The XML for the video from the API.
			 */
			$response->add_item(apply_filters('anvato_mexp_response_item', $item, $video));
		}

		return $response;
	}

	function generate_mexp_response_for_channel($results) 
        {
		$response = new MEXP_Response();
		
		foreach ($results as $channel) {
			
			$item = new MEXP_Response_Item();
			$item->set_content(sanitize_text_field((string) $channel->channel_name) );
			$item->add_meta("category", "Live Stream");
			$item->add_meta("duration", "00:00");
			$item->add_meta("embed_id", "{$channel->embed_id}");

			$icon_url = (string) $channel->icon_url;
			$icon_url = $icon_url === "" ? ANVATO_URL . 'mexp/img/channel_icon.png' : $icon_url;
			$item->set_id( (string) $channel->embed_id );
			$item->set_thumbnail( $icon_url );
			$item->url = $this->generate_shortcode((string) $channel->embed_id );
			$item->set_date( time() );
			$item->set_date_format("M j, Y, g:i a");
			/**
			 * Filter the video item to be added to the response.
			 *
			 * @param  MEXP_Response_Item $item The response item.
			 * @param  SimpleXMLElement $video The XML for the video from the API.
			 */
			$response->add_item(apply_filters('anvato_mexp_response_item', $item, $channel));
			
			/**
			 * Add Monetized Channels
			 */
			if ( !empty( $channel->monetized_channels ) )
			{

                            foreach ( (array) $channel->monetized_channels as $mchannel )
                            {   
                                $item = new MEXP_Response_Item();
                                $item->set_content(sanitize_text_field((string) $mchannel->monetized_name) );
                                $item->add_meta("category", "Monetized Live Stream");
                                $item->add_meta("duration", "00:00");
                                $item->add_meta("embed_id", "{$mchannel->embed_id}");
                                $item->set_id( (string) $mchannel->embed_id );
                                $item->set_thumbnail( (string) $channel->icon_url );
                                $item->set_date( time() );
                                $item->set_date_format("M j, Y, g:i a");
                                $item->url = $this->generate_shortcode((string) $mchannel->embed_id);
                                $response->add_item(apply_filters('anvato_mexp_response_item', $item, $mchannel));
                            }
			}
			
		}

		return $response;
	}

	/**
	 * Generate an [anvplayer] shortcode for use in the editor.
	 *
	 * @param int $video The video ID
	 * @return string The shortcode
	 */
	private function generate_shortcode( $video_id ) {
		return '[anvplayer video="' . esc_attr($video_id) . '"]';
	}

	/**
	 * Returns an array of tabs for the Anvato service's media manager panel.
	 *
	 * @param array $tabs Associative array of default tab items.
	 * @return array Associative array of tabs. The key is the tab ID and the value is an array of tab attributes.
	 */
	public function tabs(array $tabs) {
		$tabs[ANVATO_DOMAIN_SLUG] = array(
			'all' => array(
				'defaultTab' => true,
				'text' => _x('All', 'Tab title', ANVATO_DOMAIN_SLUG),
				'fetchOnRender' => true,
			),
		);

		return $tabs;
	}

	/**
	 * Returns an array of custom text labels for the Anvato service.
	 *
	 * @param array $labels Associative array of default labels.
	 * @return array Associative array of labels.
	 */
	public function labels(array $labels) {
		$labels[ANVATO_DOMAIN_SLUG] = array(
			'insert' => __('Insert Video', ANVATO_DOMAIN_SLUG),
			'noresults' => __('No videos matched your search query.', ANVATO_DOMAIN_SLUG),
			'title' => __('Insert Anvato Video', ANVATO_DOMAIN_SLUG),
			'loadmore' => __('Load more videos', ANVATO_DOMAIN_SLUG),
		);

		return $labels;
	}

}