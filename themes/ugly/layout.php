<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title>
		<?php if ( $zcms->getError() ) { ?>
			Error <?php echo $zcms->getError(); ?> /
		<?php } ?>
			<?php echo $zcms->getTitle(); ?>
		</title>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
		<link rel="stylesheet" href="<?php echo $zcms->getThemeDir(); ?>/styles.css" />
	</head>
	<body>
		<div id="main">
			<h1 class="title">
				<?php echo $zcms->getTitle(); ?>
			</h1>
			<div id="content">
				<?php echo $zcms->getNavi(); ?>
				<?php echo $zcms->getBreadCrump(); ?>
				<?php echo $zcms->getContent(); ?>
			</div>
		</div>
		<?php if ( $zcms->isAdmin() ) { ?>
		<div id="adminbar">
			<a href="?edit=1">Edit Page</a>
			|
			<a href="?clear-cache=1">Clear Caches</a>
			|
			<a href="?logout=1">Logout</a>
		</div>
		<?php } ?>
		<div id="footer">
			Powered by <!--<a class="none" href="http://github.com/ukautz/zero-cms">ZeroCMS</a>
			&amp;-->
			<a href="http://fortrabbit.de" class="none">
				<em style="text-decoration: none; color: red">•</em>
				<span style="font: italic bold 14px/32px Georgia,serif">fortrabbit</span>
			</a>
		</div>
		<?php if ( !$zcms->isAdmin()
			&& $_SERVER[ 'SERVER_NAME' ] == 'www.decency-antispam.org' 
		) { ?>
		<script type="text/javascript">
			var _gaq = _gaq || [];
			_gaq.push(['_setAccount', 'UA-431527-5']);
			_gaq.push(['_trackPageview']);
			
			(function() {
				var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
				ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
				var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
			})();
		</script>
		<?php } ?>
	</body>
</html>

