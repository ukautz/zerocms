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
		<div id="footer">
			Powered by <a class="none" href="http://github.com/ukautz/zerocms">ZeroCMS</a>
		</div>
	</body>
</html>

