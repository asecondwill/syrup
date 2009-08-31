<?php
class GeomapHelper extends AppHelper {
	/**
	 * Helpers
	 *
	 * @var array
	 */
	public $helpers = array('Html', 'Javascript');

	/**
	 * Service information
	 *
	 * @var array
	 */
	protected $services = array(
		'google' => array(
			'url' => 'http://www.google.com/jsapi?key=${key}'
		),
		'yahoo' => array(
			'url' => 'http://api.maps.yahoo.com/ajaxymap?v=3.8&appid=${key}'
		)
	);

	/**
	 * Get map HTML + JS code
	 *
	 * @param array $center If specified, center map in this location
	 * @param array $markers Add these markers (each marker is array('point' => (x, y), 'title' => '', 'content' => ''))
	 * @param array $parameters Parameters (service, key, id, width, height, zoom, div)
	 * @return string HTML + JS code
	 */
	public function map($center = null, $markers = array(), $parameters = array()) {
		$parameters = array_merge(array(
			'service' => Configure::read('Geocode.service'),
			'key' => Configure::read('Geocode.key'),
			'id' => null,
			'width' => 500,
			'height' => 300,
			'zoom' => 10,
			'div' => array(),
			'type' => 'street',
			'layout' => array(
				'pan',
				'panAndZoom',
				'scale',
				'types',
				'zoom'
			)
		), $parameters);

		if (empty($parameters['service'])) {
			$parameters['service'] = 'google';
		}
		$service = strtolower($parameters['service']);
		if (!isset($this->services[$service]) || empty($parameters['key'])) {
			return false;
		}

		$this->Javascript->link(str_replace('${key}', $parameters['key'], $this->services[$service]['url']), false);

		$out = '';

		if (empty($parameters['id'])) {
			$parameters['id'] = 'map_' . Security::hash(uniqid(time(), true));
			$out .= $this->Html->div(
				!empty($parameters['div']['class']) ? $parameters['div']['class'] : null,
				'<!-- ' . $service . ' map -->',
				array_merge($parameters['div'], array('id'=>$parameters['id']))
			);
		}

		if (!empty($markers)) {
			foreach($markers as $i => $marker) {
				if (is_array($marker) && count($marker) == 2 && isset($marker[0]) && isset($marker[1]) && is_numeric($marker[0]) && is_numeric($marker[1])) {
					$marker = array('point' => $marker);
				}
				$marker = array_merge(array(
					'point' => null,
					'title' => null,
					'content' => null
				), $marker);

				if (empty($marker['point'])) {
					unset($markers[$i]);
					continue;
				}

				foreach(array('title', 'content') as $parameter) {
					if (!empty($marker[$parameter])) {
						$marker[$parameter] = str_replace(
							array('"', "\n"),
							array('\\"', '\\n'),
							$marker[$parameter]
						);
					}
				}

				$markers[$i] = $marker;
			}
			$markers = array_values($markers);
		}

		if (empty($center)) {
			$center = !empty($markers) ? $markers[0]['point'] : array(0, 0);
		}

		$out .= $this->{'_'.$service}($parameters['id'], $center, $markers, $parameters);
		return $out;
	}

	/**
	 * Google Map
	 *
	 * @param string $id Container ID
	 * @param array $center If specified, center map in this location
	 * @param array $markers Add these markers (each marker is array('point' => (x, y), 'title' => '', 'content' => ''))
	 * @param array $parameters Parameters (service, key, id, width, height, zoom, div)
	 * @return string HTML + JS code
	 */
	protected function _google($id, $center, $markers, $parameters) {
		$varName = 'm' . $id;
		$mapTypes = array(
			'street' => 'G_NORMAL_MAP',
			'satellite' => 'G_SATELLITE_MAP',
			'hybrid' => 'G_HYBRID_MAP'
		);
		$layouts = array(
			'elements' => array(
				'panAndZoom' => '${var}.addControl(new google.maps.LargeMapControl3D())',
				'scale' => '${var}.addControl(new google.maps.ScaleControl())',
				'types' => '${var}.addControl(new google.maps.MapTypeControl())',
				'zoom' => '${var}.addControl(new google.maps.SmallZoomControl3D())'
			)
		);

		$script = '
			var ' . $varName . ' = null;
			google.load("maps", "2");
			google.setOnLoadCallback(function () {
				if (!google.maps.BrowserIsCompatible()) {
					return false;
				}
				var mapOptions = {};
		';

		if (!empty($parameters['width']) && !empty($parameters['height'])) {
			$script .= '
				mapOptions.size = new google.maps.Size(' . $parameters['width'] . ', ' . $parameters['height'] . ');
			';
		}

		$script .= '
				' . $varName . ' = new google.maps.Map2(document.getElementById("' . $id . '"), mapOptions);
				' . $varName . '.setMapType(' . $mapTypes[$parameters['type']] . ');
		';

		if (!empty($center)) {
			list($latitude, $longitude) = $center;
			$script .= $varName . '.setCenter(new google.maps.LatLng(' . $latitude . ', ' .	$longitude . '));' . "\n";
		}

		if (!empty($parameters['zoom'])) {
			$script .= $varName . '.setZoom(' . $parameters['zoom'] . ');' . "\n";
		}

		if (!empty($markers)) {
			foreach($markers as $marker) {
				$markerOptions = array(
					'title' => null,
					'content' => null
				);
				$markerOptions = array_filter(array_intersect_key($marker, $markerOptions));
				$content = (!empty($markerOptions['content']) ? $markerOptions['content'] : null);
				if (!empty($content)) {
					unset($markerOptions['content']);
				}

				$markerOptions = !empty($markerOptions) ? $this->Javascript->object($markerOptions) : '{}';
				list($latitude, $longitude) = $marker['point'];
				$script .= '
					marker = new google.maps.Marker(new google.maps.LatLng(' . $latitude . ', ' . $longitude . '), ' . $markerOptions . ');
				';

				if (!empty($content)) {
					$script .= '
						google.maps.Event.addListener(marker, \'click\', function() {
							marker.openInfoWindowHtml("' . $content . '");
						});
					';
				}

				$script .= $varName . '.addOverlay(marker);' . "\n";
			}
		}

		if (!empty($parameters['layout'])) {
			foreach($parameters['layout'] as $element => $enabled) {
				unset($parameters['layout'][$element]);
				if (is_numeric($element)) {
					$element = $enabled;
					$enabled = true;
				}
				$parameters['layout'][$element] = $enabled;
			}

			if (!empty($parameters['layout']['panAndZoom']) && !empty($parameters['layout']['zoom'])) {
				$parameters['layout']['zoom'] = false;
			}

			foreach($parameters['layout'] as $element => $enabled) {
				if ($enabled && !empty($layouts['elements'][$element])) {
					$script .= str_replace('${var}', $varName, $layouts['elements'][$element]) . ';' . "\n";
				}
			}
		}

		$script .= '});';

		return $this->Javascript->codeBlock($script);
	}

	/**
	 * Yahoo Map
	 *
	 * @param string $id Container ID
	 * @param array $center If specified, center map in this location
	 * @param array $markers Add these markers (each marker is array('point' => (x, y), 'title' => '', 'content' => ''))
	 * @param array $parameters Parameters (service, key, id, width, height, zoom, div)
	 * @return string HTML + JS code
	 */
	protected function _yahoo($id, $center, $markers, $parameters) {
		$varName = 'm' . $id;
		$mapTypes = array(
			'street' => 'YAHOO_MAP_REG',
			'satellite' => 'YAHOO_MAP_SAT',
			'hybrid' => 'YAHOO_MAP_HYB'
		);
		$layouts = array(
			'elements' => array(
				'pan' => '${var}.addPanControl()',
				'scale' => '${var}.addZoomScale()',
				'types' => '${var}.addTypeControl()',
				'zoom' => '${var}.addZoomLong()'
			)
		);

		$script = '
			var ' . $varName . ' = new YMap(document.getElementById("' . $id . '"));
		';

		$script .= $varName . '.setMapType(' . $mapTypes[$parameters['type']] . ');' . "\n";

		if (!empty($center)) {
			list($latitude, $longitude) = $center;
			$script .= $varName . '.drawZoomAndCenter(new YGeoPoint(' . $latitude . ', ' . $longitude . '));' . "\n";
		}

		if (!empty($parameters['width']) && !empty($parameters['height'])) {
			$script .= $varName . '.resizeTo(new YSize(' . $parameters['width'] . ', ' . $parameters['height'] . '));' . "\n";
		}

		if (!empty($parameters['zoom'])) {
			$script .= $varName . '.setZoomLevel(' . $parameters['zoom'] . ');' . "\n";
		}

		$script .= $varName . '.removeZoomScale();' . "\n";

		if (!empty($parameters['layout'])) {
			foreach($parameters['layout'] as $element => $enabled) {
				unset($parameters['layout'][$element]);
				if (is_numeric($element)) {
					$element = $enabled;
					$enabled = true;
				}
				$parameters['layout'][$element] = $enabled;
			}

			foreach($parameters['layout'] as $element => $enabled) {
				if ($enabled && !empty($layouts['elements'][$element])) {
					$script .= str_replace('${var}', $varName, $layouts['elements'][$element]) . ';' . "\n";
				}
			}
		}

		if (!empty($markers)) {
			foreach($markers as $marker) {
				list($latitude, $longitude) = $marker['point'];
				$script .= 'content = null' . "\n";
				if (!empty($marker['content'])) {
					$script .= 'content = "' . $marker['content'] . '";' . "\n";
				}
				$script .= '
					marker = new YMarker(new YGeoPoint(' . $latitude . ', ' . $longitude . '));
					YEvent.Capture(marker, EventsList.MouseClick, function(o) {
						marker.openSmartWindow(content);
					});
					' . $varName . '.addOverlay(marker);
				';
			}
		}

		return $this->Javascript->codeBlock($script);
	}
}
?>
