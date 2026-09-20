<?php

# Menu entries for the LOF Audio Supply plugin.
# $menuEntries is an array of menu entries for this plugin.
#   text - menu entry text
#   type - status/content/output/help (main menu area)
#   page - PHP file to display for this menu item
#   wrap - 0/1 to hide or display the FPP header, menu, and footer on this page
#
# config.php is rendered as a fragment (wrap = 1) and refuses to render at all
# if it is fetched directly rather than included by FPP's plugin dispatcher,
# because FPP's authentication lives on the dispatcher path.

$menuEntries = Array(
	Array(
		'text' => 'LOF Audio Supply',
		'type' => 'content',
		'page' => 'config.php',
		'wrap' => 1
	)
);

##############################################################################
# Display the menu entries for this plugin.
# It is expected that two variables are already set:
# $plugin - contains the name of the current plugin directory/repoName
# $menu - contains the name of the menu section/type
foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	if (preg_match('/^http.?:\/\//', $entry['page']))
	{
		printf("<li><a href='%s' target='_blank'>%s</a></li>\n",
			htmlspecialchars($entry['page'], ENT_QUOTES), htmlspecialchars($entry['text'], ENT_QUOTES));
	}
	else
	{
		$nopage = '';
		if (isset($entry['wrap']) && ($entry['wrap'] == 0))
			$nopage = '&nopage=1';

		printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
			htmlspecialchars($plugin, ENT_QUOTES), htmlspecialchars($entry['page'], ENT_QUOTES),
			$nopage, htmlspecialchars($entry['text'], ENT_QUOTES));
	}
}
?>
