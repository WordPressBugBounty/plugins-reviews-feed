<?php

namespace SmashBalloon\Reviews\Common\Badges;

use SmashBalloon\Reviews\Common\DisplayElements;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * A saved badge as a PNG at a stable URL (SBR-163).
 *
 * Email clients do not run CSS the way a browser does — no custom properties,
 * no flexbox worth relying on, and Gmail strips SVG outright. So the one format
 * that works in a signature is a raster image, served from the site, that the
 * client fetches each time it renders the message.
 *
 * The badge is drawn as SVG here and rasterised, rather than assembled with GD
 * primitives: the SVG is a description of the design (rounded surface, tabular
 * numerals, half-star clipping) that stays readable next to the CSS it mirrors,
 * where a hundred lines of imagefilledpolygon() would not.
 *
 * URL shape: home_url( '?sb-badge=<id>' ), the design's own. Public and
 * unauthenticated on purpose — a mail client fetching it has no session, and it
 * exposes exactly what the badge already shows on the site.
 *
 * Each style draws its own geometry: layout_chip(), layout_card()'s path below
 * and layout_medal(). Until 2026-09-01 there was only one, so a chip or a medal
 * emailed as a card — see BadgeImageStyleParityTest for what that looked like.
 *
 * @since 2.10.0
 */
class SBR_Badge_Image
{
	/**
	 * Query var the front-end handler answers on.
	 */
	const QUERY_VAR = 'sb-badge';

	/**
	 * Fallback display size, used only when the image cannot be produced.
	 *
	 * The real dimensions are measured per badge — the canvas hugs its content,
	 * exactly as the inline-flex badge on the page does, so there is no one size
	 * to declare. These two exist so a snippet copied while Imagick is missing
	 * still reserves a sensible box instead of collapsing to nothing.
	 */
	const FALLBACK_WIDTH = 350;
	const FALLBACK_HEIGHT = 90;

	/**
	 * The star outline, in its own 20x20 space.
	 *
	 * A constant because two renderers read it now: Imagick wraps it in an SVG,
	 * and SBR_Badge_Gd parses it into a polygon. Every command is a line, which is
	 * what makes the second one possible — but the path mixes absolute `L` with
	 * relative `l` and relies on implicit repeats, so it is parsed, never assumed.
	 */
	const STAR_PATH = 'M10 15.27L16.18 19l-1.64-7.03L20 7.24l-7.19-.61L10 0 7.19 6.63 0 7.24l5.46 4.73L3.82 19z';

	/**
	 * Whether this host can turn the badge SVG into a PNG at all.
	 *
	 * Imagick, specifically — GD cannot rasterise SVG, so `wp_image_editor_supports()`
	 * is the wrong question: it answers true on a GD-only host that can never draw
	 * this badge. Measured on a GD+FreeType host with no Imagick: build_svg() returns
	 * valid SVG and render_png() still answers ''.
	 *
	 * @return bool
	 */
	public static function can_rasterise()
	{
		return self::rasteriser() !== '';
	}

	/**
	 * Which rung of the chain will draw this badge.
	 *
	 * Imagick first, because it is the renderer the design was built against and
	 * the one this plugin has always used. GD second: measured against Imagick on
	 * four badges it reproduces the geometry exactly and differs only in edge
	 * antialiasing, and it is present on effectively every host — including the
	 * ones without Imagick, which is most shared hosting.
	 *
	 * There is deliberately no third rung. Between them these two cover
	 * essentially every host, and drawing the badge locally keeps the layout in
	 * one place: anything remote would need the SVG shipped off-site, which buys
	 * a rendering surface nobody can bound from here.
	 *
	 * @return string 'imagick', 'gd', or '' when neither can draw it.
	 */
	public static function rasteriser()
	{
		if (class_exists('\Imagick')) {
			return 'imagick';
		}

		if (SBR_Badge_Gd::available()) {
			return 'gd';
		}

		return '';
	}

	/**
	 * Hook the endpoint.
	 *
	 * @return void
	 */
	public static function register()
	{
		add_filter('query_vars', array( __CLASS__, 'add_query_var' ));
		add_action('template_redirect', array( __CLASS__, 'maybe_serve' ));
	}

	/**
	 * @param array $vars Registered query vars.
	 *
	 * @return array
	 */
	public static function add_query_var($vars)
	{
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * The directory rendered badges are cached in, or '' when uploads is unusable.
	 *
	 * @return string Absolute path with no trailing slash, or ''.
	 */
	protected static function cache_dir()
	{
		$uploads = wp_get_upload_dir();

		if (empty($uploads['basedir']) || ! is_string($uploads['basedir'])) {
			return '';
		}

		return trailingslashit($uploads['basedir']) . 'sbr-badges';
	}

	/**
	 * Delete a badge's rendered files.
	 *
	 * Globs `badge-<id>-*`, not `badge-<id>-*.png`: the writer renders to
	 * `<file>.<random>.tmp` and renames, so a process death between the write and
	 * the rename strands a file the narrower pattern could never match — it does not
	 * end in `.png`. That file sits in a web-served uploads directory.
	 *
	 * Called on every render (sweeping older renders of the same badge) and on
	 * delete. Before, only the render path swept, so a DELETED badge's PNG was never
	 * removed by anything: the sweep runs on the next render of that badge, which
	 * will not happen. Its rating, review count and platform marks outlived the
	 * badge and survived uninstall.
	 *
	 * @param int    $badge_id Badge post ID.
	 * @param string $keep     Absolute path to spare, for the render path.
	 * @return int Files deleted.
	 */
	public static function purge($badge_id, $keep = '')
	{
		$dir = self::cache_dir();

		if ($dir === '') {
			return 0;
		}

		$found = glob($dir . '/badge-' . absint($badge_id) . '-*');

		// glob() returns false on error, and casting THAT to an array gives
		// array{0: false} rather than an empty one — one iteration handing
		// wp_delete_file a boolean.
		$deleted = 0;

		foreach (is_array($found) ? $found : array() as $path) {
			if ($path === $keep || ! is_file($path)) {
				continue;
			}

			wp_delete_file($path);
			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Public URL for a badge's image.
	 *
	 * @param int $badge_id Badge post id.
	 *
	 * @return string
	 */
	public static function url($badge_id)
	{
		return add_query_arg(self::QUERY_VAR, absint($badge_id), home_url('/'));
	}

	/**
	 * The `<img>` a signature editor's source view accepts.
	 *
	 * Width and height are declared so a mail client reserves the right box
	 * before the image arrives — without them the signature reflows as it loads.
	 * They are the DISPLAY size, half the rendered one.
	 *
	 * The alt text states the rating in words. It is what a screen reader
	 * announces, and it is also what shows when a client blocks images, which is
	 * the common case on first open — so it has to carry the same claim the
	 * picture does.
	 *
	 * @param int   $badge_id Badge post id.
	 * @param array $settings Sanitised badge settings.
	 *
	 * @return string
	 */
	public static function snippet($badge_id, $settings)
	{
		$feed_id = SBR_Trust_Badge_Builder::feed_for_badge($settings);
		// The same resolver the HTML badge uses, so the two cannot disagree.
		$aggregate = SBR_Badge_Renderer::aggregate_for_badge($settings, $feed_id);

		// No markup when there is no picture to point it at. build_svg() and
		// cached_png() both refuse on an unresolved badge, so an <img> here would
		// be a permanently broken image in someone's email signature.
		if (empty($aggregate['resolved'])) {
			return '';
		}

		$scale = isset($aggregate['scale']) ? (int) $aggregate['scale'] : 5;

		// Resolved is not rated: a feed with nothing rated in it resolves, and the
		// rated sentence would read "Rated 0.0 out of 5 from 0 reviews" to a screen
		// reader while the page says "No reviews yet".
		$alt = SBR_Badge_Renderer::is_rated($aggregate['rating'], $aggregate['count'])
			? sprintf(
				/* translators: 1: rating, 2: scale maximum, 3: number of reviews */
				__('Rated %1$s out of %2$d from %3$s reviews', 'reviews-feed'),
				number_format_i18n((float) $aggregate['rating'], 1),
				$scale,
				number_format_i18n((int) $aggregate['count'])
			)
			: __('No reviews yet', 'reviews-feed');

		// Rendered here, not just measured: an <img> is only honest if there are
		// bytes behind it. render_png() answers '' when Imagick cannot rasterise the
		// SVG — its internal XML renderer will not open the `data:` URIs the half
		// star and the bitmap provider marks are embedded as, so most badges produce
		// nothing on a host without a librsvg delegate. Offering the snippet anyway
		// puts a permanently broken image in someone's email signature.
		$png = self::cached_png($badge_id, $settings);

		if ($png === '') {
			return '';
		}

		// The MEASURED size, not a constant. The canvas hugs its content now —
		// a badge with five platform marks and a footer is a different shape
		// from a bare rating — so a fixed 350x67 squashed every one of them into
		// the wrong aspect ratio. Read off the image above, which is cached, and
		// halve it: the PNG is drawn at 2x and declared at half so it lands crisp
		// on a retina client.
		$size = self::size_of_png($png);

		return sprintf(
			'<img src="%s" width="%d" height="%d" alt="%s">',
			esc_url(self::url($badge_id)),
			$size['w'],
			$size['h'],
			esc_attr($alt)
		);
	}

	/**
	 * The declared size for a PNG blob we already hold.
	 *
	 * @param string $png Raw PNG bytes, or ''.
	 *
	 * @return array{w: int, h: int}
	 */
	protected static function size_of_png($png)
	{
		if ($png !== '') {
			$size = @getimagesizefromstring($png); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt blob returns false, which is handled below.

			if (is_array($size) && ! empty($size[0]) && ! empty($size[1])) {
				// Drawn at 2x, declared at half.
				return array(
					'w' => (int) round($size[0] / 2),
					'h' => (int) round($size[1] / 2),
				);
			}
		}

		return array( 'w' => self::FALLBACK_WIDTH, 'h' => self::FALLBACK_HEIGHT );
	}

	/**
	 * Serve the PNG when the query var is present.
	 *
	 * @return void
	 */
	public static function maybe_serve()
	{
		$badge_id = absint(get_query_var(self::QUERY_VAR));

		if ($badge_id < 1) {
			return;
		}

		$badge = SBR_Trust_Badge_Store::get($badge_id);

		// Published only. get() answers for any status, which is right for the
		// admin screens that call it — but this endpoint is anonymous, so
		// without the check a draft or trashed badge was fetchable by anyone who
		// guessed the id, and the image is the one surface with no UI around it
		// to say so.
		if ($badge === null || ! isset($badge['status']) || $badge['status'] !== 'publish') {
			status_header(404);
			exit;
		}

		$png = self::cached_png($badge_id, $badge['settings']);

		if ($png === '') {
			// No image rather than a broken one. A mail client showing its own
			// "image missing" placeholder is honest; a 200 with a corrupt body
			// is not, and some clients cache the failure.
			status_header(503);
			exit;
		}

		// A signature image is fetched on every open, by clients that cache
		// aggressively. An hour keeps the rating current without asking the site
		// to redraw for every reader.
		$ttl = (int) apply_filters('sbr_badge_image_ttl', HOUR_IN_SECONDS, $badge_id);

		header('Content-Type: image/png');
		header('Content-Length: ' . strlen($png));
		header('Cache-Control: public, max-age=' . $ttl);
		header('X-Content-Type-Options: nosniff');

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image body
		echo $png;
		exit;
	}

	/**
	 * The badge's PNG, rendered once per distinct appearance.
	 *
	 * The pitch called for a CACHED server-side image, and the reason shows up
	 * here: a signature image is fetched by every recipient of every email, and
	 * rasterising per request would run Imagick and an aggregate lookup on each
	 * one. Cached in uploads rather than a transient — twenty kilobytes of
	 * binary per badge does not belong in the options table.
	 *
	 * The key is a fingerprint of the settings AND the numbers, so a rating that
	 * moves or a theme that changes produces a different file. Nothing has to
	 * remember to purge it; the old file simply stops being asked for.
	 *
	 * @param int   $badge_id Badge post id.
	 * @param array $settings Sanitised badge settings.
	 *
	 * @return string PNG bytes, empty when the image cannot be produced.
	 */
	public static function cached_png($badge_id, $settings)
	{
		$feed_id = SBR_Trust_Badge_Builder::feed_for_badge($settings);
		// The same resolver the HTML badge uses, so the two cannot disagree.
		$aggregate = SBR_Badge_Renderer::aggregate_for_badge($settings, $feed_id);

		// SBRVER is part of the key, not decoration: the fingerprint is what
		// decides whether a cached file is still valid, and without the version
		// a plugin update that changes how the badge is DRAWN invalidates
		// nothing. A badge whose settings and numbers both sit still would keep
		// serving the old picture indefinitely — which is exactly what happened
		// when the star accent started being honoured here.
		$encoded = wp_json_encode(array( SBRVER, $settings, $aggregate ));

		// Unencodable settings would give every such badge the same fingerprint
		// and therefore the same cache file. Render fresh instead of colliding.
		if (! is_string($encoded)) {
			return self::render_png($settings);
		}

		$fingerprint = md5($encoded);
		$uploads     = wp_get_upload_dir();

		if (! empty($uploads['error'])) {
			// Unwritable uploads is not a reason to serve nothing — just render
			// every time and let the HTTP cache do what it can.
			return self::render_png($settings);
		}

		if (empty($uploads['basedir']) || ! is_string($uploads['basedir'])) {
			return self::render_png($settings);
		}

		$dir  = self::cache_dir();
		$file = $dir . '/badge-' . absint($badge_id) . '-' . $fingerprint . '.png';

		if (is_readable($file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own cache file
			$cached = file_get_contents($file);

			if ($cached !== false && $cached !== '') {
				return $cached;
			}
		}

		$png = self::render_png($settings);

		if ($png === '') {
			return '';
		}

		if (wp_mkdir_p($dir)) {
			// Sweep this badge's older renders, so a site that changes a badge
			// often does not accumulate a file per edit. Keeps the file about to
			// be written.
			self::purge($badge_id, $file);

			// Write to a unique temp file, then rename. rename() is atomic within
			// a filesystem, so a reader either sees the whole PNG or no file at
			// all. Writing in place is not: this endpoint is unauthenticated and
			// its path is deterministic, so two cold-cache requests for the same
			// badge interleave their writes and maybe_serve() hands back a
			// truncated image as a 200 with an hour of Cache-Control on it.
			$temp = $file . '.' . wp_generate_password(8, false) . '.tmp';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- writing our own cache file
			if (@file_put_contents($temp, $png) === strlen($png)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unwritable cache is not worth a warning; the render is returned either way.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
				if (! @rename($temp, $file)) {
					wp_delete_file($temp);
				}
			} else {
				wp_delete_file($temp);
			}
		}

		return $png;
	}

	/**
	 * Rasterise a badge's settings to PNG bytes.
	 *
	 * @param array $settings Sanitised badge settings.
	 *
	 * @return string PNG bytes, empty when the image cannot be produced.
	 */
	public static function render_png($settings)
	{
		// The SVG is built once and handed to whichever rung can draw it. Building it
		// needs no rasteriser at all — every coordinate comes from text_width()'s
		// metric table — which is what lets the rungs agree on the geometry.
		$rasteriser = self::rasteriser();

		if ($rasteriser === 'imagick') {
			$png = self::render_png_imagick($settings);

			if ($png !== '') {
				return $png;
			}

			// Imagick is INSTALLED but could not draw it — most often a policy.xml
			// with the SVG/MSVG coder disabled, which is standard hardening after
			// CVE-2016-3714. Without this the chain stopped here and the email
			// badge was unavailable on a host where GD was sitting right there and
			// perfectly able. `rasteriser()` cannot see this: it can only ask
			// whether the class exists, not whether this build reads SVG.
			if (! SBR_Badge_Gd::available()) {
				return '';
			}

			$rasters = array();
			$svg     = self::build_svg($settings, $rasters);

			return $svg === '' ? '' : SBR_Badge_Gd::render($svg, $rasters);
		}

		if ($rasteriser === 'gd') {
			$rasters = array();
			$svg     = self::build_svg($settings, $rasters);

			return $svg === '' ? '' : SBR_Badge_Gd::render($svg, $rasters);
		}

		return '';
	}

	/**
	 * Rasterise a badge's settings to PNG bytes with Imagick.
	 *
	 * @param array $settings Sanitised badge settings.
	 *
	 * @return string PNG bytes, empty when the image cannot be produced.
	 */
	public static function render_png_imagick($settings)
	{
		if (! class_exists('\Imagick')) {
			return '';
		}

		// The rasters come back SEPARATELY rather than embedded as `data:` URIs.
		// ImageMagick's internal SVG renderer treats such an href as a filename and
		// throws for the whole document, so a half star or a bitmap provider mark
		// used to cost the entire PNG on any host without a librsvg delegate
		// (SBR-495). Composited below instead, which behaves the same everywhere.
		$rasters = array();
		$svg     = self::build_svg($settings, $rasters);

		if ($svg === '') {
			return '';
		}

		try {
			$image = new \Imagick();
			$image->setBackgroundColor(new \ImagickPixel('transparent'));
			$image->readImageBlob($svg);
			$image->setImageFormat('png');

			self::composite_rasters($image, $rasters);

			return (string) $image->getImageBlob();
		} catch (\Throwable $e) {
			// An Imagick build with no SVG support lands here. '' means "this
			// rasteriser could not", NOT "the badge cannot be drawn" — render_png()
			// tries GD next. The previous comment claimed this was the same answer
			// as having no Imagick; it was the opposite, because no Imagick means
			// rasteriser() picks GD and the badge draws.
			return '';
		}
	}

	/**
	 * Draw the collected rasters onto the rasterised badge.
	 *
	 * Each entry carries the geometry the SVG would have given its `<image>`, in the
	 * same coordinate space, so compositing lands them where the layout put them.
	 *
	 * A mark that cannot be read is SKIPPED rather than allowed to throw. That is what
	 * makes logo_pngs()' all-or-nothing rule true in practice: it decides which marks
	 * are drawable, and a surprise here costs that one mark instead of the whole badge.
	 * The stars are not in that category — they are generated, not bundled — so if they
	 * ever fail the badge is still honest, just plain.
	 *
	 * @param \Imagick $canvas  The rasterised badge.
	 * @param array    $rasters Entries of x, y, w, h, png.
	 *
	 * @return void
	 */
	protected static function composite_rasters($canvas, array $rasters)
	{
		foreach ($rasters as $raster) {
			try {
				$layer = new \Imagick();
				$layer->setBackgroundColor(new \ImagickPixel('transparent'));
				$layer->readImageBlob((string) $raster['png']);

				// The PNGs are generated at the size the layout asked for, but resize
				// when they are not: a bundled mark's intrinsic size is whatever the
				// file happens to be, and scaling here keeps the geometry authoritative.
				if ($layer->getImageWidth() !== (int) $raster['w'] || $layer->getImageHeight() !== (int) $raster['h']) {
					$layer->resizeImage((int) $raster['w'], (int) $raster['h'], \Imagick::FILTER_LANCZOS, 1);
				}

				$canvas->compositeImage($layer, \Imagick::COMPOSITE_OVER, (int) $raster['x'], (int) $raster['y']);
				$layer->clear();
			} catch (\Throwable $e) {
				continue;
			}
		}
	}

	/**
	 * One raster's place in the badge: recorded for compositing, or embedded.
	 *
	 * Embedding is kept for the case where no collector was passed, so build_svg()
	 * still produces a self-contained document for anything that wants one.
	 *
	 * @param int         $x       Left, in SVG units.
	 * @param int         $y       Top, in SVG units.
	 * @param int         $w       Width.
	 * @param int         $h       Height.
	 * @param string      $png     Raw PNG bytes.
	 * @param array|null  $rasters Collector, by reference, or null to embed.
	 *
	 * @return string SVG markup, or '' when the raster was collected instead.
	 */
	protected static function raster_tag($x, $y, $w, $h, $png, &$rasters)
	{
		if (is_array($rasters)) {
			$rasters[] = array(
				'x'   => (int) $x,
				'y'   => (int) $y,
				'w'   => (int) $w,
				'h'   => (int) $h,
				'png' => (string) $png,
			);

			return '';
		}

		return sprintf(
			'<image x="%d" y="%d" width="%d" height="%d" xlink:href="data:image/png;base64,%s"/>',
			(int) $x,
			(int) $y,
			(int) $w,
			(int) $h,
			base64_encode((string) $png)
		);
	}

	/**
	 * Build the badge as a self-contained SVG.
	 *
	 * Self-contained matters: no custom properties, no external font, no CSS
	 * file. The rasteriser sees one document, and what it draws cannot depend on
	 * a stylesheet it never loads.
	 *
	 * @param array                                                                    $settings Sanitised badge settings.
	 * @param array<int, array{x: int, y: int, w: int, h: int, png: string}>|null $rasters  Collector: when an array is
	 *                                                                                     passed, rasters are recorded
	 *                                                                                     for compositing instead of
	 *                                                                                     embedded as `data:` URIs.
	 *
	 * @return string SVG markup, empty when there is nothing truthful to draw.
	 */
	protected static function build_svg($settings, &$rasters = null)
	{
		$feed_id = SBR_Trust_Badge_Builder::feed_for_badge($settings);

		// The function that DRAWS, on the same resolver as snippet() and
		// cached_png() — otherwise the pixels, the alt text and the cache
		// fingerprint each report a different number.
		$aggregate = SBR_Badge_Renderer::aggregate_for_badge($settings, $feed_id);

		// Same refusal the renderer makes: an unresolvable feed publishes
		// nothing rather than a badge reading "no reviews yet" for a business
		// that has them.
		if (empty($aggregate['resolved'])) {
			return '';
		}

		$rating = (float) $aggregate['rating'];
		$count  = (int) $aggregate['count'];
		$scale  = isset($aggregate['scale']) ? (int) $aggregate['scale'] : 5;

		$theme = isset($settings['theme']) ? (string) $settings['theme'] : 'light';
		// The accent is stored as a HUE string, with '' meaning "no accent, keep
		// the default gold" — a state distinct from '0', which is red. -1 is the
		// same "unset" the renderer uses, so both surfaces agree.
		$accent_hue = ( isset($settings['accent']) && $settings['accent'] !== '' && is_numeric($settings['accent']) )
			? (int) $settings['accent']
			: -1;

		$palette = self::palette(
			$theme,
			isset($settings['tint_hue']) ? (int) $settings['tint_hue'] : 250,
			$accent_hue
		);

		$font = 'Helvetica, Arial, -apple-system, sans-serif';

		$scale_factor = 2;

		// Which shape to draw. This used to be missing entirely: every badge was
		// drawn to the card's geometry, so a chip emailed as a card reading
		// "Based on N reviews" while the page showed "N" in brackets, and a
		// medal emailed horizontal. Card stays the default for an unrecognised
		// value, matching sanitize_args() on the renderer.
		$style = ( isset($settings['style']) && in_array($settings['style'], array( 'chip', 'card', 'medal' ), true) )
			? (string) $settings['style']
			: 'card';

		if ($style !== 'card') {
			$ctx = array(
				'settings'     => $settings,
				'aggregate'    => $aggregate,
				'palette'      => $palette,
				'rating'       => $rating,
				'count'        => $count,
				'scale'        => $scale,
				'scale_factor' => $scale_factor,
				'font'         => $font,
			);

			return $style === 'chip'
				? self::layout_chip($ctx, $rasters)
				: self::layout_medal($ctx, $rasters);
		}

		// EVERY number below is measured off the rendered card, not chosen: the
		// image and the page have to be the same badge, and the first version of
		// this method was drawn from coordinates that matched nothing. The live
		// card is 352x90 with 16/14 padding, a 24px/700 rating, 16px stars at a
		// 2px gap, a 12px count and a 25px footer band. It was being drawn as
		// 350x67 with 27px text and 26px stars, so the email badge was a
		// different, squatter object wearing the same colours.
		//
		// 1x here, doubled on the way out, so these stay comparable to what the
		// browser reports.
		$pad_x     = 16;
		$pad_y     = 14;
		$gap       = 14;
		$col_gap   = 4;
		$val_size  = 24;
		$cnt_size  = 12;
		$star_size = 16;
		$star_gap  = 2;
		$foot_h    = 25;
		$foot_size = 10;
		$radius    = 12;

		$content = self::measure($settings, $aggregate, $palette, $rating, $count, array(
			'pad_x'     => $pad_x,
			'pad_y'     => $pad_y,
			'gap'       => $gap,
			'col_gap'   => $col_gap,
			'val_size'  => $val_size,
			'cnt_size'  => $cnt_size,
			'star_size' => $star_size,
			'star_gap'  => $star_gap,
			'foot_h'    => $foot_h,
			'scale'     => $scale_factor,
		));

		$value       = $content['value'];
		$count_label = $content['count_label'];
		$footer_text = $content['footer_text'];
		$marks       = $content['marks'];
		$val_w       = $content['val_w'];
		$stars_w     = $content['stars_w'];
		$cnt_w       = $content['cnt_w'];
		$col_w       = $content['col_w'];
		$logos_w     = $content['logos_w'];
		$mark_h      = $content['mark_h'];
		$col_h       = $content['col_h'];
		$main_h      = $content['main_h'];
		$w           = $content['w'];
		$h           = $content['h'];

		$svg = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">',
			$w,
			$h
		);

		// Surface. Drawn inset by the stroke's half-width so the 1px border sits
		// inside the canvas rather than being clipped by it.
		$svg .= sprintf(
			'<rect x="1" y="1" width="%d" height="%d" rx="%d" fill="%s" stroke="%s" stroke-width="2"/>',
			$w - 2,
			$h - 2,
			$radius * $scale_factor,
			esc_attr($palette['surface']),
			esc_attr($palette['line'])
		);

		// The footer is a BAND, not a line of text floating at the bottom: a
		// tinted strip the full width of the badge, which is what .sbr-tb-foot
		// is. Drawn before the content so its top edge cannot cover a descender.
		if ($footer_text !== '') {
			$svg .= self::footer_band_svg(
				$footer_text,
				$w,
				$h,
				(int) round(( $main_h + 1 ) * $scale_factor),
				$radius * $scale_factor,
				$foot_size * $scale_factor,
				$font,
				$palette,
				$scale_factor
			);
		}

		// Content row, vertically centred in the main area exactly as
		// `align-items: center` centres it.
		$mid = ( $pad_y + ( ( $main_h - ( $pad_y * 2 ) ) / 2 ) + 1 ) * $scale_factor;
		$x   = ( $pad_x + 1 ) * $scale_factor;

		if ($value !== '') {
			$svg .= sprintf(
				// dominant-baseline is not honoured by every rasteriser, so the
				// baseline is computed: half the cap height below the middle.
				'<text x="%d" y="%d" font-family="%s" font-size="%d" font-weight="700" fill="%s">%s</text>',
				$x,
				(int) ( $mid + ( $val_size * 0.36 * $scale_factor ) ),
				esc_attr($font),
				$val_size * $scale_factor,
				esc_attr($palette['ink']),
				esc_html($value)
			);

			$x += ( $val_w + $gap ) * $scale_factor;
		}

		if ($col_w > 0) {
			$col_top = $mid - ( ( $col_h / 2 ) * $scale_factor );

			if ($stars_w > 0) {
				$stars_png = self::stars_png(
					$scale === 10 ? ( $rating / 2 ) : $rating,
					$palette,
					$star_size,
					$star_gap,
					$scale_factor
				);

				if ($stars_png !== '') {
					$svg .= self::raster_tag(
						(int) $x,
						(int) $col_top,
						$stars_w * $scale_factor,
						$star_size * $scale_factor,
						$stars_png,
						$rasters
					);
				}
			}

			if ($cnt_w > 0) {
				$cnt_top = $stars_w > 0
					? $col_top + ( ( $star_size + $col_gap ) * $scale_factor )
					: $col_top;

				$svg .= sprintf(
					'<text x="%d" y="%d" font-family="%s" font-size="%d" fill="%s">%s</text>',
					$x,
					(int) ( $cnt_top + ( 11 * $scale_factor ) ),
					esc_attr($font),
					$cnt_size * $scale_factor,
					esc_attr($palette['muted']),
					esc_html($count_label)
				);
			}

			$x += $col_w * $scale_factor;
		}

		if ($logos_w > 0) {
			$svg .= self::logo_row_svg($marks, $x + ( $gap * $scale_factor ), $mid, $mark_h, $palette, $scale_factor, $rasters);
		}

		return $svg . '</svg>';
	}

	/**
	 * The chip, drawn.
	 *
	 * Two centred rows in a grid: value + stars + count on one line, the logo
	 * row under it. Every number is read off the rendered chip in a browser,
	 * the same way the card's were — `.sbr-tb-main` is `grid; gap: 8px;
	 * justify-items: center; padding: 10px 16px`, `.sbr-tb-row` is `flex;
	 * gap: 6px; align-items: center`, the value is 14px/700, the count 12px and
	 * the stars 14px. Measured 2026-09-01 at 179.41 x 58.5 for a one-lockup
	 * chip; this canvas hugs its content so the width follows the text.
	 *
	 * @param array{settings: array<string, mixed>, aggregate: array<string, mixed>, palette: array{surface: string, ink: string, muted: string, line: string, star: string, starBase: string, foot: string, chip: string}, rating: float, count: int, scale: int, scale_factor: int, font: string} $ctx Shared drawing context.
	 * @param array<int, array<string, mixed>>|null $rasters Collected rasters.
	 *
	 * @return string
	 */
	protected static function layout_chip($ctx, &$rasters = null)
	{
		$scale = $ctx['scale_factor'];
		$font  = $ctx['font'];
		$pal   = $ctx['palette'];

		$pad_x   = 16;
		$pad_y   = 10;
		$row_gap = 8;
		$in_gap  = 6;
		$val_sz  = 14;
		$cnt_sz  = 12;
		$star_sz = 14;
		$star_gp = 2;
		$foot_h  = 25;
		$foot_sz = 10;
		$radius  = 12;

		$parts = self::content_parts($ctx['settings'], $ctx['aggregate'], $pal, $ctx['rating'], $ctx['count']);

		$value   = $parts['value'];
		$cnt_txt = $parts['count_label'];
		$foot    = $parts['footer_text'];
		$marks   = $parts['marks'];

		$val_w   = $value !== '' ? self::text_width($value, $val_sz, true) : 0;
		$stars_w = $parts['show_stars'] ? ( $star_sz * 5 ) + ( $star_gp * 4 ) : 0;
		$cnt_w   = $cnt_txt !== '' ? self::text_width($cnt_txt, $cnt_sz, false) : 0;

		$row_parts = array_values(array_filter(array( $val_w, $stars_w, $cnt_w )));
		$row_w     = array_sum($row_parts) + ( max(0, count($row_parts) - 1) * $in_gap );
		$row_h     = max($val_sz, $stars_w > 0 ? $star_sz : 0, $cnt_w > 0 ? 15 : 0);

		$logos_w = 0;
		$mark_h  = 0;
		foreach ($marks as $i => $mark) {
			$logos_w += $mark['w'] - ( $i > 0 ? 3 : 0 );
			$mark_h   = max($mark_h, $mark['h']);
		}

		$inner_w = max($row_w, $logos_w);
		$main_h  = $row_h + ( $logos_w > 0 ? $row_gap + $mark_h : 0 ) + ( $pad_y * 2 );

		$w = (int) round(( $inner_w + ( $pad_x * 2 ) + 2 ) * $scale);
		$h = (int) round(( $main_h + ( $foot !== '' ? $foot_h : 0 ) + 2 ) * $scale);

		$svg  = self::open_svg($w, $h);
		$svg .= self::surface_svg($w, $h, $radius * $scale, $pal);

		if ($foot !== '') {
			$svg .= self::footer_band_svg(
				$foot,
				$w,
				$h,
				(int) round(( $main_h + 1 ) * $scale),
				$radius * $scale,
				$foot_sz * $scale,
				$font,
				$pal,
				$scale
			);
		}

		// justify-items: center — each row is centred on the badge, not on the
		// widest row's left edge.
		$row_x  = ( ( $w / $scale ) - $row_w ) / 2;
		$row_mid = ( $pad_y + ( $row_h / 2 ) + 1 );

		$x = $row_x;

		if ($value !== '') {
			$svg .= self::text_svg($x * $scale, ( $row_mid + ( $val_sz * 0.36 ) ) * $scale, $font, $val_sz * $scale, $pal['ink'], $value, true);
			$x   += $val_w + $in_gap;
		}

		if ($stars_w > 0) {
			$png = self::stars_png(
				$ctx['scale'] === 10 ? ( $ctx['rating'] / 2 ) : $ctx['rating'],
				$pal,
				$star_sz,
				$star_gp,
				$scale
			);
			if ($png !== '') {
				$svg .= self::raster_tag(
					(int) round($x * $scale),
					(int) round(( $row_mid - ( $star_sz / 2 ) ) * $scale),
					$stars_w * $scale,
					$star_sz * $scale,
					$png,
					$rasters
				);
			}
			$x += $stars_w + $in_gap;
		}

		if ($cnt_w > 0) {
			$svg .= self::text_svg($x * $scale, ( $row_mid + ( $cnt_sz * 0.36 ) ) * $scale, $font, $cnt_sz * $scale, $pal['muted'], $cnt_txt, false);
		}

		if ($logos_w > 0) {
			$logo_x   = ( ( $w / $scale ) - $logos_w ) / 2;
			$logo_mid = ( $pad_y + $row_h + $row_gap + ( $mark_h / 2 ) + 1 ) * $scale;
			$svg     .= self::logo_row_svg($marks, $logo_x * $scale, $logo_mid, (int) $mark_h, $pal, $scale, $rasters, false);
		}

		return $svg . '</svg>';
	}

	/**
	 * The medal, drawn.
	 *
	 * One centred column: value, stars, count, logo row. Measured off the
	 * rendered medal — `.sbr-tb-main` is `flex; flex-direction: column;
	 * align-items: center; gap: 6px; padding: 20px 28px 14px`, the value is
	 * 30px/700, the count 12px, the stars 15px, and `.sbr-tb-logos` carries an
	 * extra 2px of margin-top. Measured 2026-09-01 at 229.7 x 108.5 for a
	 * Booking medal, which has no star row.
	 *
	 * @param array{settings: array<string, mixed>, aggregate: array<string, mixed>, palette: array{surface: string, ink: string, muted: string, line: string, star: string, starBase: string, foot: string, chip: string}, rating: float, count: int, scale: int, scale_factor: int, font: string} $ctx Shared drawing context.
	 * @param array<int, array<string, mixed>>|null $rasters Collected rasters.
	 *
	 * @return string
	 */
	protected static function layout_medal($ctx, &$rasters = null)
	{
		$scale = $ctx['scale_factor'];
		$font  = $ctx['font'];
		$pal   = $ctx['palette'];

		$pad_x     = 28;
		$pad_top   = 20;
		$pad_btm   = 14;
		$stack_gap = 6;
		$logo_top  = 2;
		$val_sz    = 30;
		$cnt_sz    = 12;
		$star_sz   = 15;
		$star_gp   = 2;
		$foot_h    = 25;
		$foot_sz   = 10;
		$radius    = 12;

		$parts = self::content_parts($ctx['settings'], $ctx['aggregate'], $pal, $ctx['rating'], $ctx['count']);

		$value   = $parts['value'];
		$cnt_txt = $parts['count_label'];
		$foot    = $parts['footer_text'];
		$marks   = $parts['marks'];

		$stars_w = $parts['show_stars'] ? ( $star_sz * 5 ) + ( $star_gp * 4 ) : 0;

		$logos_w = 0;
		$mark_h  = 0;
		foreach ($marks as $i => $mark) {
			$logos_w += $mark['w'] - ( $i > 0 ? 3 : 0 );
			$mark_h   = max($mark_h, $mark['h']);
		}

		$plan    = self::medal_rows($parts, $stars_w, $logos_w, $mark_h, array(
			'val_sz'    => $val_sz,
			'cnt_sz'    => $cnt_sz,
			'star_sz'   => $star_sz,
			'stack_gap' => $stack_gap,
			'logo_top'  => $logo_top,
		));
		$rows    = $plan['rows'];
		$inner_w = $plan['inner_w'];

		$main_h = $plan['stack_h'] + $pad_top + $pad_btm;
		$w      = (int) round(( $inner_w + ( $pad_x * 2 ) + 2 ) * $scale);
		$h      = (int) round(( $main_h + ( $foot !== '' ? $foot_h : 0 ) + 2 ) * $scale);

		$svg  = self::open_svg($w, $h);
		$svg .= self::surface_svg($w, $h, $radius * $scale, $pal);

		if ($foot !== '') {
			$svg .= self::footer_band_svg(
				$foot,
				$w,
				$h,
				(int) round(( $main_h + 1 ) * $scale),
				$radius * $scale,
				$foot_sz * $scale,
				$font,
				$pal,
				$scale
			);
		}

		$centre = ( $w / $scale ) / 2;
		$y      = $pad_top + 1;

		foreach ($rows as $i => $row) {
			if ($i > 0) {
				$y += $stack_gap + ( $row['kind'] === 'logos' ? $logo_top : 0 );
			}

			$left = $centre - ( $row['w'] / 2 );

			if ($row['kind'] === 'value') {
				$svg .= self::text_svg($left * $scale, ( $y + ( $val_sz * 0.72 ) ) * $scale, $font, $val_sz * $scale, $pal['ink'], $value, true);
			} elseif ($row['kind'] === 'stars') {
				$png = self::stars_png(
					$ctx['scale'] === 10 ? ( $ctx['rating'] / 2 ) : $ctx['rating'],
					$pal,
					$star_sz,
					$star_gp,
					$scale
				);
				if ($png !== '') {
					$svg .= self::raster_tag((int) round($left * $scale), (int) round($y * $scale), $stars_w * $scale, $star_sz * $scale, $png, $rasters);
				}
			} elseif ($row['kind'] === 'count') {
				$svg .= self::text_svg($left * $scale, ( $y + 11 ) * $scale, $font, $cnt_sz * $scale, $pal['muted'], $cnt_txt, false);
			} else {
				$svg .= self::logo_row_svg($marks, $left * $scale, ( $y + ( $mark_h / 2 ) ) * $scale, (int) $mark_h, $pal, $scale, $rasters, false);
			}

			$y += $row['h'];
		}

		return $svg . '</svg>';
	}

	/**
	 * Which rows the medal stacks, in order, and how tall the stack comes out.
	 *
	 * Gaps are counted between PRESENT rows only, so a badge with its count
	 * hidden does not keep the space where the count would have been — the same
	 * thing `gap` does to a flex column with fewer children.
	 *
	 * @param array{value: string, count_label: string, show_stars: bool} $parts   Content.
	 * @param float                                                       $stars_w Star row width, 0 when hidden.
	 * @param float                                                       $logos_w Logo row width, 0 when hidden.
	 * @param float                                                       $mark_h  Tallest mark.
	 * @param array{val_sz: int, cnt_sz: int, star_sz: int, stack_gap: int, logo_top: int} $m Metrics at 1x.
	 *
	 * @return array{rows: array<int, array{kind: string, h: float, w: float}>, inner_w: float, stack_h: float}
	 */
	protected static function medal_rows($parts, $stars_w, $logos_w, $mark_h, $m)
	{
		$rows = array();

		if ($parts['value'] !== '') {
			$rows[] = array(
				'kind' => 'value',
				'h'    => (float) $m['val_sz'],
				'w'    => self::text_width($parts['value'], $m['val_sz'], true),
			);
		}

		if ($stars_w > 0) {
			$rows[] = array( 'kind' => 'stars', 'h' => (float) $m['star_sz'], 'w' => (float) $stars_w );
		}

		if ($parts['count_label'] !== '') {
			$rows[] = array(
				'kind' => 'count',
				'h'    => 15.0,
				'w'    => self::text_width($parts['count_label'], $m['cnt_sz'], false),
			);
		}

		if ($logos_w > 0) {
			$rows[] = array( 'kind' => 'logos', 'h' => (float) $mark_h, 'w' => (float) $logos_w );
		}

		$inner_w = 0.0;
		$stack_h = 0.0;

		foreach ($rows as $i => $row) {
			$inner_w  = max($inner_w, $row['w']);
			$stack_h += $row['h'];

			if ($i > 0) {
				$stack_h += $m['stack_gap'] + ( $row['kind'] === 'logos' ? $m['logo_top'] : 0 );
			}
		}

		return array( 'rows' => $rows, 'inner_w' => $inner_w, 'stack_h' => $stack_h );
	}

	/**
	 * The opening `<svg>`, identical for every style.
	 *
	 * @param int $w Canvas width.
	 * @param int $h Canvas height.
	 *
	 * @return string
	 */
	protected static function open_svg($w, $h)
	{
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">',
			$w,
			$h
		);
	}

	/**
	 * The rounded surface and its border.
	 *
	 * Inset by the stroke's half-width so the 1px border sits inside the canvas
	 * rather than being clipped by it — the card's own reasoning, shared.
	 *
	 * @param int                   $w       Canvas width.
	 * @param int                   $h       Canvas height.
	 * @param int                   $radius  Corner radius, already scaled.
	 * @param array{surface: string, ink: string, muted: string, line: string, star: string, starBase: string, foot: string, chip: string} $palette Resolved colours.
	 *
	 * @return string
	 */
	protected static function surface_svg($w, $h, $radius, $palette)
	{
		return sprintf(
			'<rect x="1" y="1" width="%d" height="%d" rx="%d" fill="%s" stroke="%s" stroke-width="2"/>',
			$w - 2,
			$h - 2,
			$radius,
			esc_attr($palette['surface']),
			esc_attr($palette['line'])
		);
	}

	/**
	 * One run of text.
	 *
	 * `dominant-baseline` is not honoured by every rasteriser, so callers pass a
	 * computed baseline rather than a centre — the card learned that first.
	 *
	 * @param float  $x    Left edge, already scaled.
	 * @param float  $y    Baseline, already scaled.
	 * @param string $font Font stack.
	 * @param float  $size Font size, already scaled.
	 * @param string $fill Colour.
	 * @param string $text Text.
	 * @param bool   $bold Whether to draw at weight 700.
	 *
	 * @return string
	 */
	protected static function text_svg($x, $y, $font, $size, $fill, $text, $bold)
	{
		return sprintf(
			'<text x="%d" y="%d" font-family="%s" font-size="%d"%s fill="%s">%s</text>',
			(int) round($x),
			(int) round($y),
			esc_attr($font),
			(int) round($size),
			$bold ? ' font-weight="700"' : '',
			esc_attr($fill),
			esc_html($text)
		);
	}

	/**
	 * How wide a string renders, in the SVG's own units.
	 *
	 * The canvas has to be sized before the text is drawn, and a rasteriser
	 * offers no way to ask. These are Helvetica's published advance widths in
	 * 1/1000 em — the same metrics Arial matches, which is what the font stack
	 * resolves to on every platform that renders these images. An estimate,
	 * but a measured one: guessing a fixed per-character width made the box
	 * either clip "Based on 12,345 reviews" or leave a gap after "(12)".
	 *
	 * Unlisted characters fall back to the lowercase average rather than zero,
	 * so a non-Latin footer overflows slightly instead of collapsing.
	 *
	 * @param string $text Text to measure.
	 * @param float  $size Font size in the same units as the result.
	 * @param bool   $bold Whether the text is drawn at weight 700.
	 *
	 * @return float
	 */
	protected static function text_width($text, $size, $bold = false)
	{
		static $regular = array(
			' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667,
			"'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333,
			'.' => 278, '/' => 278, '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556,
			'5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556, ':' => 278, ';' => 278,
			'?' => 556, '@' => 1015,
			'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
			'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722,
			'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722,
			'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
			'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
			'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556,
			'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556,
			'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
		);

		// Helvetica-Bold differs enough on lowercase and punctuation to matter at
		// 24px, which is the size the rating is drawn at.
		static $bold_widths = array(
			' ' => 278, '.' => 278, ',' => 278, '(' => 333, ')' => 333, '/' => 278,
			'0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556,
			'6' => 556, '7' => 556, '8' => 556, '9' => 556,
		);

		$table = $bold ? $bold_widths + $regular : $regular;
		$total = 0;

		// Multibyte-safe: a footer can hold anything the author typed.
		$chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);

		foreach ((array) $chars as $char) {
			$total += isset($table[ $char ]) ? $table[ $char ] : 500;
		}

		return ( $total / 1000 ) * $size;
	}

	/**
	 * The optical height a provider's lockup renders at.
	 *
	 * Mirrors the per-lockup rules in sbr-trust-badge.css. Keep the two in step
	 * or the email logo renders at a different size from the badge on the page.
	 *
	 * @param string $provider Provider key.
	 *
	 * @return float CSS pixels.
	 */
	protected static function lockup_height(string $provider): float
	{
		$heights = array(
			'google'      => 24.0,
			'yelp'        => 22.0,
			'trustpilot'  => 22.5,
			'facebook'    => 14.5,
			'wordpressorg' => 23.0,
			'tripadvisor' => 13.0,
			'woocommerce' => 11.5,
			'airbnb'      => 22.0,
			'aliexpress'  => 13.5,
			'booking'     => 13.5,
		);

		// Keyed on the asset slug, like the artwork it sizes: the data slug carries a
		// dot (`wordpress.org`) that no key here has.
		$slug = DisplayElements::provider_asset_slug($provider);

		return isset($heights[ $slug ]) ? $heights[ $slug ] : 18.0;
	}

	/**
	 * The platform marks as PNGs, ready to embed.
	 *
	 * Two-stage on purpose. ImageMagick here has no librsvg — `queryFormats()`
	 * reports MSVG, its own minimal renderer — so a provider mark nested as an
	 * SVG inside the badge SVG loses anything MSVG cannot do: embedded <style>
	 * (Facebook came out a black disc), <clipPath> (Booking.com vanished),
	 * <mask>, and raster paint (AliExpress, Formidable). Rasterising each mark
	 * on its own first and embedding the PNG sidesteps every one of those,
	 * because a PNG has no features left to lose.
	 *
	 * ALL OR NOTHING. If one mark cannot be drawn the whole row is dropped,
	 * because the row is a claim about which platforms the rating came from: a
	 * badge aggregating five sources that shows three marks says something false
	 * about where its number comes from, and says it more convincingly than
	 * showing none. Two of the fifteen are embedded bitmaps (AliExpress,
	 * Formidable) that no amount of rewriting makes MSVG draw, so a feed
	 * carrying one of those gets rating, stars and count — all true — and no
	 * logo row. A host with librsvg draws every one.
	 *
	 * @param array $settings  Sanitised badge settings.
	 * @param array $providers Provider slugs, from the aggregate.
	 * @param array $palette   Resolved colours, for the mono ink.
	 *
	 * @return array<int, array{png: string, w: int, h: int, iw: int, ih: int, tint: string}>
	 */
	protected static function logo_pngs($settings, $providers, $palette = array())
	{
		// Not gated on Imagick any more: mark_for() dispatches, and on a GD-only
		// host the marks come from the derived PNG set instead of from the SVGs.
		if (self::rasteriser() === '') {
			return array();
		}

		// No cap, matching logos.php: a badge credits every platform whose reviews
		// are in its rating. The width comes from the count, so the image grows
		// with the row rather than dropping marks off the end.
		$providers = array_values($providers);

		if (! $providers) {
			return array();
		}

		// The SAME resolution the page performs, through the same two methods.
		// Reading `logo_color` and `logo_style` raw would put the email badge on
		// a different answer from the web one: tinted forces mono whatever is
		// stored, auto means mono on anything but light, and `full` collapses to
		// `icon` the moment a second source joins. The first version of this
		// method drew a stored brand mark on a tinted badge whose page version
		// was a white lockup.
		$args = array(
			'theme'      => isset($settings['theme']) ? (string) $settings['theme'] : 'light',
			'logo_color' => isset($settings['logo_color']) ? (string) $settings['logo_color'] : 'auto',
			'logo_style' => isset($settings['logo_style']) ? (string) $settings['logo_style'] : 'full',
		);

		$mono  = SBR_Badge_Renderer::resolved_logo_color($args) === 'mono';
		$style = SBR_Badge_Renderer::resolved_logo_style($args, true, count($providers));
		$ink   = isset($palette['ink']) ? (string) $palette['ink'] : '#101828';

		// Full lockup: one wide mark instead of a row, single source only —
		// resolved_logo_style() has already enforced that.
		if ($style === 'full') {
			$provider = (string) reset($providers);

			if (DisplayElements::has_provider_lockup($provider)) {
				// Drawn bare at the per-provider height, matching the page — the
				// 132x34 chip belongs to the specimen sheet, not the badge.
				// Generous width, height drives the scale: `height: Npx; width: auto`.
				$lockup = self::mark_for(
					$provider,
					'lockup',
					$mono ? $ink : '',
					// CSS pixels, like the 16,16 below — rasterise_blob() renders
					// at 2x and divides back, so doubling here draws twice the size.
					240,
					(int) round(self::lockup_height($provider))
				);

				return $lockup === null
					? array()
					: array( array( 'png' => $lockup['png'], 'w' => $lockup['w'], 'h' => $lockup['h'], 'iw' => $lockup['w'], 'ih' => $lockup['h'], 'tint' => '' ) );
			}
		}

		$out = array();

		foreach ($providers as $provider) {
			// Mono is its own artwork where it exists, for the reason
			// DisplayElements::get_provider_mono_icon() gives: recolouring a mark
			// with a knocked-out glyph turns it into a solid blob.
			$svg = ( $mono && DisplayElements::has_provider_mono_icon($provider) )
				? DisplayElements::get_provider_mono_icon($provider)
				: ( DisplayElements::has_provider_icon($provider) ? DisplayElements::get_provider_icon($provider) : '' );

			if ($svg === '') {
				return array();
			}

			// 16 inside a 24 chip: .sbr-tb-provider is content-box with 4px of
			// padding all round, so the glyph is 16 and the circle is 24. Drawing
			// the glyph at 24 — which this did first — made every mark a third
			// too big and left no room for the chip the page draws behind it.
			$variant = ( $mono && DisplayElements::has_provider_mono_icon($provider) ) ? 'mono' : 'icon';
			$mark    = self::mark_for($provider, $variant, $mono ? $ink : '', 16, 16);

			if ($mark === null) {
				return array();
			}

			$out[] = array(
				'png'  => $mark['png'],
				'w'    => 24,
				'h'    => 24,
				'iw'   => $mark['w'],
				'ih'   => $mark['h'],
				'tint' => self::provider_tint($provider, $mono, $palette),
			);
		}

		return $out;
	}

	/**
	 * What the PNG prints for the rating and the line under it.
	 *
	 * Split out of measure() so the rule lives in one place and measure() stays under
	 * the complexity ceiling. The question is `rated`, not `resolved`: a feed with
	 * nothing rated in it resolves, and printing "0.0" / "Based on 0 reviews" into a
	 * signature is a zero-star claim the page never makes — it prints "—" and
	 * "No reviews yet".
	 *
	 * @param array $settings Badge settings.
	 * @param float $rating   Aggregate rating.
	 * @param int   $count    Aggregate review count.
	 *
	 * @param string             $style               Badge style; picks the count's phrasing.
	 * @param array<int, string> $aggregate_providers Resolved provider slugs.
	 *
	 * @return array{0: string, 1: string} Value, then the count label.
	 */
	protected static function rating_texts($settings, $rating, $count, $style = 'card', $aggregate_providers = array())
	{
		$show_value = ! empty($settings['show_rating']);

		if (! SBR_Badge_Renderer::is_rated($rating, $count)) {
			return array(
				$show_value ? '—' : '',
				__('No reviews yet', 'reviews-feed'),
			);
		}

		// Each style phrases the count differently, and the image has to phrase
		// it the SAME way — the three labels come straight from the templates:
		// chip.php prints "(count_raw_label)", card.php count_based_label,
		// medal.php count_on_label. This built the card's phrasing for all three,
		// so a chip that read "4.8 (894)" on the page emailed as "Based on 894
		// reviews".
		$count_label = '';

		if (! empty($settings['show_count'])) {
			if ($style === 'chip') {
				$count_label = '(' . number_format_i18n($count) . ')';
			} elseif ($style === 'medal') {
				$provider = self::single_provider($aggregate_providers);

				$count_label = $provider !== ''
					? sprintf(
						/* translators: 1: number of reviews, 2: provider name e.g. Google */
						_n('%1$s review on %2$s', '%1$s reviews on %2$s', $count, 'reviews-feed'),
						number_format_i18n($count),
						$provider
					)
					: sprintf(
						/* translators: %s: number of reviews */
						_n('%s review', '%s reviews', $count, 'reviews-feed'),
						number_format_i18n($count)
					);
			} else {
				$count_label = sprintf(
					/* translators: %s: number of reviews */
					_n('Based on %s review', 'Based on %s reviews', $count, 'reviews-feed'),
					number_format_i18n($count)
				);
			}
		}

		return array(
			$show_value ? number_format_i18n($rating, 1) : '',
			$count_label,
		);
	}

	/**
	 * The one platform this badge speaks for, or ''.
	 *
	 * Only when there is exactly one: with several sources behind one number,
	 * naming one of them would attribute the whole rating to it. Same rule and
	 * same label source as SBR_Badge_Renderer::prepare().
	 *
	 * @param array<int, string> $providers Resolved provider slugs.
	 *
	 * @return string
	 */
	protected static function single_provider($providers)
	{
		/** @var list<string> $list */
		$list = array_values($providers);

		return isset($list[0]) && count($list) === 1
			? SBR_Badge_Renderer::provider_label($list[0])
			: '';
	}

	/**
	 * Everything on the badge that does not depend on which style draws it.
	 *
	 * Split out when chip and medal got their own layouts: the text, the star
	 * decision, the attribution and the platform marks are the same three
	 * answers whatever the shape, and having each layout re-derive them is how
	 * the surfaces drift apart.
	 *
	 * @param array<string, mixed> $settings  Badge settings.
	 * @param array<string, mixed> $aggregate Resolved aggregate.
	 * @param array<string, string> $palette  Resolved colours.
	 * @param float                $rating    Rating.
	 * @param int                  $count     Review count.
	 *
	 * @return array{value: string, count_label: string, footer_text: string, marks: array<int, array{png: string, w: int, h: int, iw: int, ih: int, tint: string}>, show_stars: bool}
	 */
	protected static function content_parts($settings, $aggregate, $palette, $rating, $count)
	{
		// Resolved before the labels, because the medal's count names the
		// platform when there is exactly one of them.
		$aggregate_providers = ( isset($aggregate['providers']) && is_array($aggregate['providers']) )
			? array_values(array_filter($aggregate['providers'], 'is_string'))
			: array();

		$style = ( isset($settings['style']) && in_array($settings['style'], array( 'chip', 'card', 'medal' ), true) )
			? (string) $settings['style']
			: 'card';

		list($value, $count_label) = self::rating_texts($settings, $rating, $count, $style, $aggregate_providers);

		// A 0-10 Booking score cannot be drawn on five stars without silently
		// rescaling it. prepare() suppresses the row for exactly that reason
		// (`'show_stars' => $args['stars'] && ! $is_ten`); this drew them anyway,
		// so the same badge showed a score-and-word on the page and five stars
		// in an inbox.
		$is_ten     = ( isset($aggregate['scale']) && is_numeric($aggregate['scale']) ? (int) $aggregate['scale'] : 5 ) === 10;
		$show_stars = ! empty($settings['show_stars']) && ! $is_ten;

		// The attribution is not optional and not this file's decision. Below
		// Pro Plus, or on a lapsed licence, SBR_Badge_Renderer::show_powered_by()
		// forces it and calls itself "intentionally NOT filterable" — but this
		// endpoint is unauthenticated and never asked, so the emailed badge
		// carried none for any tier. Asked through the renderer so the image and
		// the page can only ever give the same answer.
		$forced_attribution = SBR_Badge_Renderer::show_powered_by(array( 'powered_by' => false ));

		$footer_text = $forced_attribution
			? __('Powered by Smash Balloon', 'reviews-feed')
			: (
				! empty($settings['show_footer']) && ! empty($settings['footer_text']) && is_scalar($settings['footer_text'])
					? (string) $settings['footer_text']
					: ''
			);

		// The platforms come from the AGGREGATE, which is where the renderer gets
		// them (prepare() reads $aggregate['providers']). Looking them up again
		// from the badge's stored `sources` returned nothing for a badge that
		// resolves its feed by id rather than by source — while the page showed
		// three marks. One lookup, one answer — resolved above, so the labels and
		// the marks cannot disagree about which platforms are on the badge.
		$marks = ! empty($settings['show_logos'])
			? self::logo_pngs($settings, $aggregate_providers, $palette)
			: array();

		return array(
			'value'       => $value,
			'count_label' => $count_label,
			'footer_text' => $footer_text,
			'marks'       => $marks,
			'show_stars'  => $show_stars,
		);
	}

	/**
	 * How big the CARD is, and where its parts sit.
	 *
	 * Split out because build_svg() was doing three jobs — decide what to show,
	 * work out how big it is, then draw it — and the first two are pure
	 * arithmetic over the card's measured metrics. Together they carried a
	 * cyclomatic complexity of 31 against a limit of 20.
	 *
	 * Card only. Chip and medal do their own arithmetic in layout_chip() and
	 * layout_medal(); what all three share is content_parts().
	 *
	 * @param array $settings  Sanitised badge settings.
	 * @param array $aggregate Rating aggregate.
	 * @param array $palette   Resolved colours.
	 * @param float $rating    Average rating.
	 * @param int   $count     Review count.
	 * @param array{pad_x: int, pad_y: int, gap: int, col_gap: int, val_size: int, cnt_size: int, star_size: int, star_gap: int, foot_h: int, scale: int} $m The card's metrics, at 1x.
	 *
	 * @return array{value: string, count_label: string, footer_text: string, marks: array<int, array{png: string, w: int, h: int, iw: int, ih: int, tint: string}>, val_w: float, stars_w: int, cnt_w: float, col_w: float, logos_w: int, mark_h: int, col_h: float, main_h: float, w: int, h: int}
	 */
	protected static function measure($settings, $aggregate, $palette, $rating, $count, $m)
	{
		$parts       = self::content_parts($settings, $aggregate, $palette, $rating, $count);
		$value       = $parts['value'];
		$count_label = $parts['count_label'];
		$footer_text = $parts['footer_text'];
		$marks       = $parts['marks'];
		$show_stars  = $parts['show_stars'];

		// Column widths, so the canvas can hug its content the way an
		// inline-flex badge does. A fixed canvas left the content in the
		// top-left corner of a box sized for a logo row that was never drawn.
		$val_w   = $value !== '' ? self::text_width($value, $m['val_size'], true) : 0;
		$stars_w = $show_stars ? ( $m['star_size'] * 5 ) + ( $m['star_gap'] * 4 ) : 0;
		$cnt_w   = $count_label !== '' ? self::text_width($count_label, $m['cnt_size'], false) : 0;
		$col_w   = max($stars_w, $cnt_w);

		// Square marks overlap by 3, matching .sbr-tb-logos on the page; a full
		// lockup is one wide element and overlaps nothing. Widths come back from
		// the rasteriser rather than being assumed, so a lockup keeps its own
		// aspect instead of being squashed into a 24px box.
		$logos_w = 0;
		$mark_h  = 0;

		foreach ($marks as $i => $mark) {
			$logos_w += $mark['w'] - ( $i > 0 ? 3 : 0 );
			$mark_h   = max($mark_h, $mark['h']);
		}

		$inner = $val_w;
		if ($col_w > 0) {
			$inner += ( $inner > 0 ? $m['gap'] : 0 ) + $col_w;
		}
		if ($logos_w > 0) {
			// The divider is a 1px rule with `gap` on its left and 14 on its
			// right — .sbr-tb-card-src's border-left plus its padding-left.
			$inner += $m['gap'] + 1 + 14 + $logos_w;
		}

		$col_h = ( $stars_w > 0 && $cnt_w > 0 )
			? $m['star_size'] + $m['col_gap'] + 15
			: max($stars_w > 0 ? $m['star_size'] : 0, $cnt_w > 0 ? 15 : 0);

		$main_h = max($m['val_size'], $col_h, $mark_h) + ( $m['pad_y'] * 2 );
		$w      = (int) round(( $inner + ( $m['pad_x'] * 2 ) + 2 ) * $m['scale']);
		$h      = (int) round(( $main_h + ( $footer_text !== '' ? $m['foot_h'] : 0 ) + 2 ) * $m['scale']);

		return array(
			'value'       => $value,
			'count_label' => $count_label,
			'footer_text' => $footer_text,
			'marks'       => $marks,
			'val_w'       => $val_w,
			'stars_w'     => $stars_w,
			'cnt_w'       => $cnt_w,
			'col_w'       => $col_w,
			'logos_w'     => $logos_w,
			'mark_h'      => $mark_h,
			'col_h'       => $col_h,
			'main_h'      => $main_h,
			'w'           => $w,
			'h'           => $h,
		);
	}

	/**
	 * The footer strip.
	 *
	 * A BAND, not a line of text at the bottom: .sbr-tb-foot is a tinted strip
	 * the full width of the badge, and the first version of this drew only the
	 * words, which is why the email badge read as unfinished next to the page.
	 * The path follows the surface's bottom corners so the tint stops where the
	 * radius does instead of squaring them off.
	 *
	 * @param string $text    Footer copy.
	 * @param int    $w       Canvas width.
	 * @param int    $h       Canvas height.
	 * @param int    $band_y  Top of the band.
	 * @param int    $radius  Corner radius, already scaled.
	 * @param int    $size    Font size, already scaled.
	 * @param string $font    Font stack.
	 * @param array{foot: string, muted: string} $palette Resolved colours.
	 * @param int    $scale   Pixel doubling.
	 *
	 * @return string
	 */
	protected static function footer_band_svg($text, $w, $h, $band_y, $radius, $size, $font, $palette, $scale)
	{
		return sprintf(
			'<path d="M2 %1$d H%2$d V%3$d a%4$d %4$d 0 0 1 -%4$d %4$d H%5$d a%4$d %4$d 0 0 1 -%4$d -%4$d Z" fill="%6$s"/>',
			$band_y,
			$w - 2,
			$h - 2 - $radius,
			$radius,
			2 + $radius,
			esc_attr($palette['foot'])
		) . sprintf(
			'<text x="%d" y="%d" text-anchor="middle" font-family="%s" font-size="%d" fill="%s">%s</text>',
			(int) ( $w / 2 ),
			$band_y + ( 17 * $scale ),
			esc_attr($font),
			$size,
			esc_attr($palette['muted']),
			esc_html($text)
		);
	}

	/**
	 * The divider and the platform marks.
	 *
	 * Split out of build_svg(), which was carrying a cyclomatic complexity of 34
	 * against a limit of 20 once the logo row landed — and the row is a separate
	 * job from laying out the rating anyway.
	 *
	 * @param array<int, array{png: string, w: int, h: int, iw: int, ih: int, tint: string}> $marks  Rasterised marks.
	 * @param float                                                                          $rule_x Left edge of the divider.
	 * @param float                                                                          $mid    Vertical centre of the content row.
	 * @param int                                                                            $mark_h Tallest mark, for the divider.
	 * @param array{surface: string, line: string}                                           $palette Resolved colours.
	 * @param int                                                                            $scale  Pixel doubling.
	 * @param array<int, array{x: int, y: int, w: int, h: int, png: string}>|null            $rasters Collector, forwarded
	 *                                                                                               from build_svg().
	 * @param bool                                                                           $rule   Draw the card's border-left rule.
	 *
	 * @return string
	 */
	protected static function logo_row_svg($marks, $rule_x, $mid, $mark_h, $palette, $scale, &$rasters = null, $rule = true)
	{
		// The rule is .sbr-tb-card-src's border-left. Only the card has one: chip
		// and medal put the logo row on its own centred line with nothing to
		// separate it from, and drawing it there left a stray tick beside them.
		$svg = $rule
			? sprintf(
				'<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
				(int) $rule_x,
				(int) ( $mid - ( ( $mark_h / 2 ) * $scale ) ),
				$scale,
				$mark_h * $scale,
				esc_attr($palette['line'])
			)
			: '';

		$mx = $rule ? $rule_x + ( 15 * $scale ) : $rule_x;

		foreach ($marks as $i => $mark) {
			if ($i > 0) {
				// Negative sibling margin, exactly as .sbr-tb-logos stacks them:
				// the ring is what separates the marks, so a real gap would read
				// as merely spaced rather than stacked.
				$mx -= 3 * $scale;
			}

			if ($mark['tint'] !== '') {
				// The chip plus its ring. On the page the ring is a box-shadow
				// in the surface colour; a stroke renders identically and is
				// something MSVG can draw.
				$svg .= sprintf(
					'<circle cx="%d" cy="%d" r="%d" fill="%s" stroke="%s" stroke-width="%d"/>',
					(int) ( $mx + ( ( $mark['w'] / 2 ) * $scale ) ),
					(int) $mid,
					(int) ( ( $mark['w'] / 2 ) * $scale ),
					esc_attr($mark['tint']),
					esc_attr($palette['surface']),
					(int) ( 1.5 * $scale )
				);
			}

			$svg .= self::raster_tag(
				(int) ( $mx + ( ( ( $mark['w'] - $mark['iw'] ) / 2 ) * $scale ) ),
				(int) ( $mid - ( ( $mark['ih'] / 2 ) * $scale ) ),
				$mark['iw'] * $scale,
				$mark['ih'] * $scale,
				$mark['png'],
				$rasters
			);

			$mx += $mark['w'] * $scale;
		}

		return $svg;
	}

	/**
	 * The circle a mark sits in.
	 *
	 * Seven providers carry their own light brand tint in sbr-trust-badge.css;
	 * everything else falls back to the neutral, which the design says is the
	 * right answer for a mark we have no brand colour for rather than a guess at
	 * one. The oklch values are transcribed from that stylesheet, not re-picked,
	 * so the chip behind a Google mark is the same colour in an inbox as on the
	 * page.
	 *
	 * Under mono the chip goes neutral for every provider — a brand tint under a
	 * single-ink mark is the one thing mono is meant to remove.
	 *
	 * @param string $provider Provider slug.
	 * @param bool   $mono     Whether the row is drawn in one ink.
	 * @param array  $palette  Resolved colours.
	 *
	 * @return string Hex colour.
	 */
	protected static function provider_tint($provider, $mono, $palette)
	{
		// L, C, H — the same triples as the stylesheet.
		static $tints = array(
			'google'         => array( 0.953, 0.021, 262 ),
			'yelp'           => array( 0.952, 0.021, 18 ),
			'facebook'       => array( 0.952, 0.021, 259 ),
			'trustpilot'     => array( 0.945, 0.026, 169 ),
			'wordpressorg'   => array( 0.945, 0.015, 229 ),
			'wordpress.org'  => array( 0.945, 0.015, 229 ),
			'tripadvisor'    => array( 0.957, 0.031, 166 ),
			'woocommerce'    => array( 0.944, 0.032, 302 ),
		);

		if ($mono || ! isset($tints[ $provider ])) {
			// gray-100 on light; on dark and tinted the stylesheet lightens the
			// badge's own surface instead, which the palette already holds.
			return isset($palette['chip']) ? (string) $palette['chip'] : '#f3f4f6';
		}

		$tint = $tints[ $provider ];

		return self::oklch_to_hex((float) $tint[0], (float) $tint[1], (float) $tint[2]);
	}

	/**
	 * One provider mark, from whichever rasteriser can actually draw it.
	 *
	 * Imagick reads the same SVG the page serves. GD cannot read SVG at all, so it
	 * loads the derived PNG that build/generate-badge-marks.php produced from that
	 * same SVG — one source of truth, two transforms, and BadgeMarkAssetsTest
	 * fails if the committed PNGs stop matching.
	 *
	 * The order matters and used to be wrong. This branched on
	 * `class_exists('\Imagick')` alone, which is the question `rasteriser()` can
	 * answer — not the question that matters. On a host where Imagick is INSTALLED
	 * but cannot draw SVG (a policy.xml with the SVG/MSVG coder disabled, standard
	 * hardening after CVE-2016-3714), render_png() falls back to GD for the badge
	 * body while this still asked Imagick for the marks, got null for the same
	 * reason, and logo_pngs()' all-or-nothing rule dropped the WHOLE logo row. So
	 * the badge rendered on exactly the host the fallback exists for, crediting no
	 * platform at all.
	 *
	 * Now it tries Imagick and falls through to GD when that comes back empty,
	 * which also covers the per-mark case: an SVG MSVG cannot draw (facebook-mono
	 * uses a <mask>) no longer costs the row every other mark in it.
	 *
	 * @param string $provider Provider key.
	 * @param string $variant  'icon', 'mono' or 'lockup'.
	 * @param string $ink      Colour to flatten to, or '' to keep the artwork.
	 * @param int    $w        Target width in CSS pixels.
	 * @param int    $h        Target height in CSS pixels.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	protected static function mark_for($provider, $variant, $ink, $w, $h)
	{
		// `static::`, not `self::`: self:: is early-bound to this class, so a
		// subclass overriding either seam would be ignored and the test double
		// below could not reach the hardened-host combination at all.
		if (static::imagick_available()) {
			$svg = '';

			if ($variant === 'lockup' && DisplayElements::has_provider_lockup($provider)) {
				$svg = DisplayElements::get_provider_lockup($provider);
			} elseif ($variant === 'mono' && DisplayElements::has_provider_mono_icon($provider)) {
				$svg = DisplayElements::get_provider_mono_icon($provider);
			} elseif (DisplayElements::has_provider_icon($provider)) {
				$svg = DisplayElements::get_provider_icon($provider);
			}

			if (is_string($svg) && $svg !== '') {
				$mark = static::rasterise_mark($svg, $ink, $w, $h);

				if ($mark !== null) {
					return $mark;
				}
			}
		}

		return static::gd_mark($provider, $variant, $ink, $w, $h);
	}

	/**
	 * Whether this host has the Imagick class at all.
	 *
	 * A method rather than an inline `class_exists()` so a test can force the
	 * branch — the failure this guards is only reachable on a host with Imagick
	 * installed and unable to draw, which no test machine can be asked to become.
	 *
	 * @return bool
	 */
	protected static function imagick_available()
	{
		return class_exists('\Imagick');
	}

	/**
	 * One provider mark from GD's derived PNG set.
	 *
	 * @param string $provider Provider key.
	 * @param string $variant  'icon', 'mono' or 'lockup'.
	 * @param string $ink      Colour to flatten to, or '' to keep the artwork.
	 * @param int    $w        Target width in CSS pixels.
	 * @param int    $h        Target height in CSS pixels.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	protected static function gd_mark($provider, $variant, $ink, $w, $h)
	{
		if (! SBR_Badge_Gd::available()) {
			return null;
		}

		$gd = SBR_Badge_Gd::mark($provider, $variant, $ink, $w, $h);

		// Rebuilt rather than passed through: the shape is the contract both
		// rungs promise their callers, and asserting it here is cheaper than
		// trusting two renderers to keep agreeing about it.
		if (! is_array($gd) || ! isset($gd['png'], $gd['w'], $gd['h'])) {
			return null;
		}

		return array(
			'png' => (string) $gd['png'],
			'w'   => (int) $gd['w'],
			'h'   => (int) $gd['h'],
		);
	}

	/**
	 * Rasterise one mark to a PNG at 2x, or null when MSVG cannot draw it.
	 *
	 * @param string $svg   SVG markup.
	 * @param string $ink   Colour to flatten the artwork to, or '' to keep it.
	 * @param int    $w     Target width in CSS pixels.
	 * @param int    $h     Target height in CSS pixels.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	protected static function rasterise_mark(string $svg, string $ink, int $w, int $h): ?array
	{
		// Two of the fifteen marks are not vector art at all: Formidable and
		// AliExpress each wrap an embedded bitmap in a <pattern>, and MSVG draws
		// neither patterns nor <image>. The bitmap IS the mark, so it is decoded
		// and used directly rather than being asked of a renderer that cannot
		// reach it. This is what took the row from "drops out whenever one of
		// these two is connected" to complete.
		$bitmap = self::embedded_bitmap($svg);

		if ($bitmap !== '') {
			return self::rasterise_blob($bitmap, '', $w, $h);
		}

		$svg = self::msvg_safe($svg);

		if ($ink !== '') {
			// The mono artwork paints with `fill="currentColor"`, which MSVG
			// does not resolve — it has no cascade to inherit from. Substituting
			// the literal ink is the equivalent, and it also flattens any stray
			// brand colour in a mark that has no mono variant.
			$svg = str_replace('currentColor', $ink, $svg);
			$svg = (string) preg_replace('/fill="(?!none)[^"]*"/', 'fill="' . $ink . '"', $svg);
		}

		return self::rasterise_blob($svg, $ink, $w, $h);
	}

	/**
	 * Rasterise any blob Imagick can read, at 2x.
	 *
	 * @param string $blob Image bytes — SVG markup or a bitmap.
	 * @param string $ink  Unused here; kept so both callers read alike.
	 * @param int    $w    Target width in CSS pixels.
	 * @param int    $h    Target height in CSS pixels.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	protected static function rasterise_blob($blob, $ink, $w, $h)
	{
		try {
			$image = new \Imagick();
			$image->setBackgroundColor(new \ImagickPixel('transparent'));
			$image->readImageBlob($blob);
			$image->setImageFormat('png32');
			$image->resizeImage($w * 2, $h * 2, \Imagick::FILTER_LANCZOS, 1, true);

			if (self::is_blank_png($image)) {
				return null;
			}

			// The real size after bestfit, so a wide lockup is not squashed into
			// a square box by the caller.
			return array(
				'png' => $image->getImageBlob(),
				'w'   => (int) round($image->getImageWidth() / 2),
				'h'   => (int) round($image->getImageHeight() / 2),
			);
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * The bitmap inside a pattern-wrapped SVG, decoded.
	 *
	 * @param string $svg SVG markup.
	 *
	 * @return string Image bytes, or '' when the file is real vector art.
	 */
	protected static function embedded_bitmap($svg)
	{
		// Only when the whole file is a bitmap in a pattern. A vector mark that
		// happens to carry a small embedded image somewhere must NOT be replaced
		// by that image, so the pattern is part of the test rather than the
		// data URI alone.
		if (strpos($svg, '<pattern') === false) {
			return '';
		}

		if (! preg_match('/data:image\/(?:png|jpe?g);base64,([A-Za-z0-9+\/=\s]+)["\')]/', $svg, $match)) {
			return '';
		}

		$bytes = base64_decode((string) preg_replace('/\s+/', '', $match[1]), true);

		return $bytes === false ? '' : $bytes;
	}

	/**
	 * Rewrite a provider mark into something MSVG can actually draw.
	 *
	 * ImageMagick here has no librsvg — queryFormats() reports MSVG, its own
	 * minimal renderer — and MSVG fails SILENTLY: it returns a fully transparent
	 * image rather than an error. Three transforms, each measured against a mark
	 * that was coming out blank:
	 *
	 *  1. Embedded <style> is ignored, so a mark that keeps its colours in a
	 *     class renders as one flat shape. Facebook is a CorelDRAW export and
	 *     came out a black disc; folding the classes into presentation
	 *     attributes is lossless for the `fill:` declarations these files carry.
	 *  2. <clipPath> is unsupported and takes the artwork with it. Airbnb and
	 *     Booking.com both clip to a rect the size of their own canvas, so the
	 *     clip is inert and dropping the reference restores the mark unchanged.
	 *  3. A FRACTIONAL viewBox is rejected outright. TripAdvisor's is
	 *     `0 0 1333.31 1333.31` and drew nothing until it was rounded — that one
	 *     cost the longest to find, because every other property of the file is
	 *     ordinary. Rounding up never crops; it adds at most a pixel of margin.
	 *
	 * @param string $svg SVG markup.
	 *
	 * @return string
	 */
	protected static function msvg_safe($svg)
	{
		// 1 — classes to attributes.
		if (preg_match_all('/\.([A-Za-z0-9_-]+)\s*\{([^}]*)\}/', $svg, $rules, PREG_SET_ORDER)) {
			foreach ($rules as $rule) {
				$attrs = '';

				foreach (explode(';', $rule[2]) as $declaration) {
					// strpos + substr rather than explode: a limited explode is
					// typed as a plain list, so neither a count check nor list()
					// proves to the analyser that both halves are there.
					$colon = strpos($declaration, ':');

					if ($colon === false) {
						continue;
					}

					$property = trim(substr($declaration, 0, $colon));
					$value    = trim(substr($declaration, $colon + 1));

					if ($property !== '' && $value !== '') {
						$attrs .= ' ' . $property . '="' . $value . '"';
					}
				}

				$svg = str_replace('class="' . $rule[1] . '"', trim($attrs), $svg);
			}

			$svg = (string) preg_replace('/<style[^>]*>.*?<\/style>/s', '', $svg);
		}

		// 2 — inert clips.
		$svg = (string) preg_replace('/\s*clip-path="url\(#[^)]*\)"/', '', $svg);
		$svg = (string) preg_replace('/<clipPath\b.*?<\/clipPath>/s', '', $svg);

		// 3 — integer viewBox, with width/height to match.
		if (preg_match('/<svg\b[^>]*>/', $svg, $tag)) {
			$open = $tag[0];

			if (preg_match('/viewBox="\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.]+)\s+([\d.]+)\s*"/', $open, $vb)) {
				$width  = (int) ceil((float) $vb[3]);
				$height = (int) ceil((float) $vb[4]);

				$rewritten = (string) preg_replace(
					'/viewBox="[^"]*"/',
					sprintf('viewBox="%d %d %d %d"', (int) floor((float) $vb[1]), (int) floor((float) $vb[2]), $width, $height),
					$open
				);

				if (strpos($rewritten, ' width=') === false) {
					$rewritten = str_replace('<svg', sprintf('<svg width="%d" height="%d"', $width, $height), $rewritten);
				}

				$svg = str_replace($open, $rewritten, $svg);
			}
		}

		return $svg;
	}

	/**
	 * Whether a rasterised mark came out empty.
	 *
	 * MSVG does not fail loudly on what it cannot draw — it returns a fully
	 * transparent image. Embedded, that is a hole in the logo row that reads as
	 * a spacing bug rather than a missing asset, so the result has to be checked
	 * rather than trusted.
	 *
	 * trimImage, not the alpha channel statistics: getImageChannelStatistics()
	 * on this build returns no CHANNEL_ALPHA entry at all, so the obvious test
	 * silently answered "not blank" for every mark — which is exactly how three
	 * invisible marks got embedded. Trimming a uniform border collapses a wholly
	 * transparent image to 1x1, which is unambiguous.
	 *
	 * @param \Imagick $image Rasterised mark.
	 *
	 * @return bool
	 */
	protected static function is_blank_png($image)
	{
		try {
			$probe = clone $image;
			$probe->trimImage(0);

			return $probe->getImageWidth() <= 1 && $probe->getImageHeight() <= 1;
		} catch (\Throwable $e) {
			// Trim throws on an image it cannot reduce. Treat that as "has
			// content" — a mark that draws is the common case.
			return false;
		}
	}

	/**
	 * The five colours a themed badge needs, resolved to literal values.
	 *
	 * The stylesheet derives these from tokens and, for tinted, from a hue at
	 * render time. An SVG handed to a rasteriser cannot do either, so the same
	 * derivations are computed here — the tinted formula is the design's own
	 * `oklch(0.45 0.13 h)` family, converted to sRGB.
	 *
	 * @param string $theme      light|dark|tinted.
	 * @param int    $tint_hue   Hue for the tinted theme.
	 * @param int    $accent_hue Star accent hue, or -1 for the default gold.
	 *
	 * @return array{surface: string, ink: string, muted: string, line: string, star: string, starBase: string, foot: string, chip: string}
	 */
	protected static function palette($theme, $tint_hue, $accent_hue = -1)
	{
		// The accent the author picked, derived exactly as sbr-trust-badge.css
		// derives it: `oklch(0.6 0.25 h)` on light, `oklch(0.78 0.19 h)` on dark
		// — a different lightness per theme from one stored hue, which is why the
		// hue is stored rather than a hex.
		//
		// It was missing here entirely, so a badge with cyan stars on the page
		// mailed out with gold ones. Tinted is excluded on purpose and matches
		// the stylesheet: its stars are white, and the accent does not apply.
		$accent_star = ( $accent_hue >= 0 && $theme !== 'tinted' )
			? ( $theme === 'dark'
				? self::oklch_to_hex(0.78, 0.19, $accent_hue)
				: self::oklch_to_hex(0.6, 0.25, $accent_hue) )
			: '';

		if ($theme === 'dark') {
			return array(
				'surface'  => '#3a4150',
				'ink'      => '#ffffff',
				'muted'    => '#9aa1ae',
				'line'     => '#4a5160',
				'star'     => $accent_star !== '' ? $accent_star : '#f5b400',
				'starBase' => '#5b6270',
				// The footer band. On the page it is `oklch(1 0 0 / 0.05)` over
				// the surface; an SVG handed to a rasteriser cannot composite
				// alpha over a sibling, so it is pre-flattened.
				'foot'     => '#434a58',
				// The neutral chip: the stylesheet lightens the badge's own
				// surface here rather than using a grey, so the logo row belongs
				// to the badge instead of sitting on top of it.
				'chip'     => '#4a5261',
			);
		}

		if ($theme === 'tinted') {
			$rgb = self::oklch_to_hex(0.45, 0.13, $tint_hue);

			return array(
				'surface'  => $rgb,
				'ink'      => '#ffffff',
				'muted'    => self::oklch_to_hex(0.88, 0.04, $tint_hue),
				'line'     => self::oklch_to_hex(0.55, 0.12, $tint_hue),
				'star'     => '#ffffff',
				'starBase' => self::oklch_to_hex(0.58, 0.11, $tint_hue),
				// Derived from the hue like everything else here, so the footer
				// band and the chips belong to the tint rather than sitting on
				// it as grey rectangles.
				'foot'     => self::oklch_to_hex(0.41, 0.13, $tint_hue),
				'chip'     => self::oklch_to_hex(0.52, 0.12, $tint_hue),
			);
		}

		return array(
			'surface'  => '#ffffff',
			'ink'      => '#101828',
			'muted'    => '#6b7280',
			'line'     => '#e5e7eb',
			'star'     => $accent_star !== '' ? $accent_star : '#f5b400',
			'starBase' => '#e5e7eb',
			'foot'     => '#f9fafb',
			'chip'     => '#f3f4f6',
		);
	}

	/**
	 * The star row, rasterised.
	 *
	 * Returned as an <image> rather than paths, and the half star is why.
	 * templates/frontend/badges/stars.php draws a half by CLIPPING a whole star,
	 * which is what a browser wants — but MSVG applies `clip-path` to the wrong
	 * side, so 4.8 came out with the fifth star filled on the RIGHT: the exact
	 * inverse of the page. Two other SVG routes were measured and neither
	 * survives MSVG either — a two-stop `linearGradient` (the design's own
	 * technique) resolves in the wrong coordinate space, and a nested `<svg>`
	 * viewport is dropped entirely.
	 *
	 * So the halving happens where it is unambiguous: each glyph is rasterised
	 * whole, and the accent copy is CROPPED to its left half before being
	 * composited over the base copy. Cropping a bitmap has one meaning on every
	 * renderer there is.
	 *
	 * @param float $rating  Rating out of 5.
	 * @param array{star: string, starBase: string} $palette Resolved colours.
	 * @param int   $size    Glyph box in CSS pixels.
	 * @param int   $gap     Space between glyphs in CSS pixels.
	 * @param int   $scale   Pixel doubling.
	 *
	 * @return string PNG bytes, or '' when the row cannot be drawn.
	 */
	protected static function stars_png($rating, $palette, $size, $gap, $scale)
	{
		// GD draws the row from the same STAR_PATH, as a polygon. Checked before the
		// Imagick branch would return '' — a badge with no stars is a worse answer
		// than one drawn by the other rasteriser.
		if (! class_exists('\Imagick')) {
			return SBR_Badge_Gd::star_row($rating, $palette, $size, $gap, $scale);
		}

		$full = self::star_png($palette['star'], $size * $scale);
		$base = self::star_png($palette['starBase'], $size * $scale);

		if ($full === null || $base === null) {
			return '';
		}

		// Floors to a half for the same reason the template does: five glyphs
		// cannot draw 4.8.
		$display = floor(max(0, min(5, (float) $rating)) * 2) / 2;

		try {
			$row = new \Imagick();
			$row->newImage(
				( ( $size * 5 ) + ( $gap * 4 ) ) * $scale,
				$size * $scale,
				new \ImagickPixel('transparent'),
				'png32'
			);

			for ($i = 0; $i < 5; $i++) {
				$left      = $i * ( $size + $gap ) * $scale;
				$remainder = $display - $i;

				if ($remainder >= 1) {
					$row->compositeImage(clone $full, \Imagick::COMPOSITE_OVER, (int) $left, 0);
					continue;
				}

				$row->compositeImage(clone $base, \Imagick::COMPOSITE_OVER, (int) $left, 0);

				if ($remainder === 0.5) {
					$half = clone $full;
					// LEFT half, which is the half the page fills.
					$half->cropImage((int) ( ( $size * $scale ) / 2 ), $size * $scale, 0, 0);
					$row->compositeImage($half, \Imagick::COMPOSITE_OVER, (int) $left, 0);
				}
			}

			$row->setImageFormat('png32');

			return $row->getImageBlob();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * One star glyph, filled and rasterised.
	 *
	 * @param string $fill Hex colour.
	 * @param int    $px   Square size in pixels.
	 *
	 * @return \Imagick|null
	 */
	protected static function star_png($fill, $px)
	{
		$path = self::STAR_PATH;

		$svg = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 20 20"><path d="%2$s" fill="%3$s"/></svg>',
			$px,
			$path,
			esc_attr($fill)
		);

		try {
			$glyph = new \Imagick();
			$glyph->setBackgroundColor(new \ImagickPixel('transparent'));
			$glyph->readImageBlob($svg);
			$glyph->setImageFormat('png32');

			return $glyph;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * oklch -> sRGB hex.
	 *
	 * The same conversion the sidebar's colour readout uses in JS, ported so the
	 * image, the badge and the picker all describe one colour. Out-of-gamut
	 * channels clamp, which is what a browser does with an oklch() it cannot
	 * display.
	 *
	 * @param float $l Lightness 0-1.
	 * @param float $c Chroma.
	 * @param float $h Hue in degrees.
	 *
	 * @return string
	 */
	protected static function oklch_to_hex($l, $c, $h)
	{
		$hr = deg2rad((float) $h);
		$a  = $c * cos($hr);
		$b  = $c * sin($hr);

		$l_ = pow($l + 0.3963377774 * $a + 0.2158037573 * $b, 3);
		$m_ = pow($l - 0.1055613458 * $a - 0.0638541728 * $b, 3);
		$s_ = pow($l - 0.0894841775 * $a - 1.2914855480 * $b, 3);

		$linear = array(
			4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
			-1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
			-0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_,
		);

		$hex = '#';

		foreach ($linear as $channel) {
			$v = max(0.0, min(1.0, $channel));
			$v = $v <= 0.0031308 ? 12.92 * $v : 1.055 * pow($v, 1 / 2.4) - 0.055;
			$hex .= str_pad(dechex((int) round($v * 255)), 2, '0', STR_PAD_LEFT);
		}

		return $hex;
	}
}
