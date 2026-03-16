<?php
/** @var array<string,mixed> $props */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap lift-teleport-wrap">
  <div id="lift-teleport-admin-app"
       data-props="<?php echo esc_attr(wp_json_encode($props)); ?>"></div>
</div>
