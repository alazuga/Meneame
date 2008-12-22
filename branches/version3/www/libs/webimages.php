<?
class WebImage {
	static $visited = array();
	var $x = 0;
	var $y = 0;
	var $image = false;
	var $checked = false;
	var $url = false;
	var $referer = '';
	var $same_domain = false;
	var $type = 'external';

	function __construct($imgtag = '', $referer = '') {
		if (!$imgtag) return;
		$this->tag = $imgtag;
		//echo "TAG: " . htmlentities($this->tag) . "<br>\n";
		if (!preg_match('/src=["\']{0,1}([^"\' ]+)/i', $this->tag, $matches)) {
			if (!preg_match('/["\']*([\da-z\/]+\.jpg)["\']*/i', $this->tag, $matches)) {
				return;
			}
		}
		$url = clean_input_url($matches[1]);
		//echo "URL: ".htmlentities($imgtag)." -> ".htmlentities($url)."<br>\n";
		if (strlen($url) < 5 || WebImage::$visited[$url] ) return;
		 WebImage::$visited[$url] = true;

		$parsed_referer = parse_url($referer);
		if (preg_match('/^\/\//', $url)) { // it's an absolute url wihout http:
			$this->url = "http:$url";
		} elseif (!preg_match('/https*:\/\//', $url)) {
			$this->url = $parsed_referer['scheme'].'://'.$parsed_referer['host'];
			if ($parsed_referer['port']) $this->url .= ':'.$parsed_referer['port'];
			if (preg_match('/^\/+/', $url)) {
				$this->url .= $url;
			} else {
				$this->url .= normalize_path(dirname($parsed_referer['path']).'/'.$url);
			}
			//echo "PARSED: $url -> $this->url <br>\n";
		} else {
			$this->url = $url;
		}
		$parsed_url = parse_url($this->url);
		$this->referer = $referer;
		// Check if domain.com are the same for the referer and the url
		if (preg_replace('/.*?([^\.]+\.[^\.]+)$/', '$1', $parsed_url['host']) == preg_replace('/.*?([^\.]+\.[^\.]+)$/', '$1', $parsed_referer['host']) || preg_match('/gfx\.|cdn\.|imgs*\.|\.img|media\.|cache\.|static\.|\.ggpht.com|upload/', $parsed_url['host'])) {
			$this->same_domain = true;
		}
		if(preg_match('/[ "]width *[=:][ "]*(\d+)/i', $this->tag, $match)) {
			$this->x = $match[1];
		}
		if(preg_match('/[ "]height *[=:][ "]*(\d+)/i', $this->tag, $match)) {
			$this->y = $match[1];
		}
	}

	function get() {
		$res = get_url($this->url);
		$this->checked = true;
		if ($res) {
			$this->content_type = $res['content_type'];
			return $this->fromstring($res['content']);
		} 
		echo "Failed to get $this->url<br>";
	}

	function fromstring($imgstr, $url = false) {
		$this->image = @imagecreatefromstring($imgstr);
		if ($this->image !== false) {
			$this->type = 'local';
			$this->x = imagesx($this->image);
			$this->y = imagesy($this->image);
			if ($url) {
				$this->url = clean_input_url($url);
				$this->same_domain = true; // We consider it from the same domain
				$this->checked = true;
			}
			//echo "Local: $this->same_domain X: $this->x Y: $this->y<br>\n";
			return true;
		}
		return false;
	}

	function surface() {
		return $this->x * $this->y;
	}

	function good() {
		if ($this->same_domain && ! $this->checked) {
			$this->get();
		}
		if (preg_match('/\/gif/i', $this->content_type) || preg_match('/\.gif/', $this->url)) {
			$min_size = 140;
			$min_surface = 28000;
		} else {
			$min_size = 80;
			$min_surface = 18000;
		}
		//echo "$this->url Content_type:  $this->content_type surface: $min_surface<br>";
		return $this->x >= $min_size && $this->y >= $min_size && $this->surface() > $min_surface && (max($this->x, $this->y) / min($this->x, $this->y)) < 3.5 && !preg_match('/button|banner|\/ban[_\/]|\/ads\/|\/pub\//', $this->url);
	}

	function scale($size=100) {
		if (!$this->image && ! $this->checked)  $this->get();
		if (!$this->image) return false;
		if ($this->x > $this->y) {
			$percent = $size/$this->x;
		} else {
			$percent = $size/$this->y;
		}
		$min = min($this->x*$percent, $this->y*$percent);
		if ($min < $size/2.2) $percent = $percent * $size/2.2/$min; // Ensure then minimux is size/2.2
		$new_x = round($this->x*$percent);
		$new_y = round($this->y*$percent);
		$dst = ImageCreateTrueColor($new_x,$new_y);
		if(imagecopyresampled($dst,$this->image,0,0,0,0,$new_x,$new_y,$this->x,$this->y)) {
			$this->image = $dst;
			$this->x=imagesx($this->image);
			$this->y=imagesy($this->image);
			return true;
		} 
		return false;
	}
	function save($filename) {
		if (!$this->image) return false;
		return imagejpeg($this->image, $filename, 85);
	}

}

class HtmlImages {
	var $html = '';
	var $selected = false;

	function __construct($url) {
		$this->url = $url;
	}

	function get() {
		$res = get_url($this->url);
		if (!$res) return;
		if (preg_match('/^image/i', $res['content_type'])) {
			$img = new WebImage();
			if ($img->fromstring($res['content'], $this->url) && $img->good()) {
				$this->selected = $img;
			}
		} elseif (preg_match('/text\/html/i', $res['content_type'])) {
			$html = $res['content'];
			$html = preg_replace('/^.*?<body[^>]*?>/is', '', $html); // Search for body
			$html = preg_replace('/<!--.+?-->/s', '', $html); // Delete commented HTML
			$html = preg_replace('/<style[^>]*?>.+?<\/style>/is', '', $html); // Delete javascript
			$html = preg_replace('/<script[^>]*?>.*?<\/script>/is', '', $html); // Delete javascript
			/* $html = preg_replace('/^.*?<h1[^>]*?>/is', '', $html); // Search for a h1 */
			$html = substr($html, 0, 18000); // Only analyze first X bytes
			$this->html = $html;
			$this->parse_img();
		}
		return $this->selected;
	}

	function parse_img() {
		preg_match_all('/(<img [^>]*>|["\'][\da-z\/]+\.jpg["\'])/i', $this->html, $matches);
		$goods = $n = 0;
		foreach ($matches[0] as $match) {
			//echo htmlentities($match) . "<br>\n";
			$img = new WebImage($match, $this->url);
			if ($img->same_domain && $img->good()) {
				$goods++;
				if (!$this->selected || ($this->selected->surface() < $img->surface() / ($n+2))) {
					$this->selected = $img;
					$n++;
					echo "<!-- CANDIDATE: ". htmlentities($img->url)." X: $img->x Y: $img->y -->\n";
				}
			}
			if ($goods > 5 && $n > 0) break;
		}
		if ($this->selected && ! $this->selected->image) {
			$this->selected->get();
		}
		return $this->selected;
	}
}

function normalize_path($path) {
	$path = preg_replace('~/\./~', '/', $path);
    // resolve /../
    // loop through all the parts, popping whenever there's a .., pushing otherwise.
	$parts = array();
	foreach (explode('/', preg_replace('~/+~', '/', $path)) as $part) {
		if ($part === "..") {
			array_pop($parts);
		} elseif ($part) {
			$parts[] = $part;
		}
	}
	return '/' . implode("/", $parts);
}

function get_url($url) {
	$session = curl_init();
	curl_setopt($session, CURLOPT_URL, $url);
	curl_setopt($session, CURLOPT_USERAGENT, "meneame.net");
	curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($session, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($session, CURLOPT_MAXREDIRS, 20);
	curl_setopt($session, CURLOPT_TIMEOUT, 20);
	$result['content'] = curl_exec($session);
	if (!$result['content']) return false;
	$result['content_type'] = curl_getinfo($session, CURLINFO_CONTENT_TYPE);
	curl_close($session);
	return $result;
}
?>