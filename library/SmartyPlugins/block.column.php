<?php
/**
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package vanilla-smarty
 * @since 2.0
 */

/**
 *
 *
 * @param array $params The parameters passed into the function.
 * @param string $content
 * @param object $smarty The smarty object rendering the template.
 * @param bool $repeat
 * @return string The url.
 */
function smarty_block_column($params, $content, &$smarty, &$repeat) {
    if (!$repeat){
        $class = '_column '.trim(val('class', $params, ''));
        return <<<EOT
        <div class="$class">
            $content
        </div>
EOT;
    }
}
