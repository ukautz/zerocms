<?php
$zcmsSearchPlugin = $zcms->getPlugin( 'searchengine' );
?>
<?php if ( $zcmsSearchPlugin->hasSearchString() ) { ?>
	<?php if ( !$zcmsSearchPlugin->hasSearchResult() ) { ?>
<div>
	<p>
		Nothing found for "<?php echo $zcmsSearchPlugin->getSearchStringHtml() ?>"
	</p>
</div>
	<?php } else { ?>
<div>
	<h2>
		Found <?php echo $zcmsSearchPlugin->getResultCount() ?> pages
		for "<?php echo $zcmsSearchPlugin->getSearchStringHtml() ?>"
	</h2>
	<ul>
		<?php foreach ( $zcmsSearchPlugin->getSearchResults() as $link_path ) { ?>
		<?php
			list( $title, $sample ) = $zcmsSearchPlugin->getContentSample( $link_path );
		?>
		<li>
			<a href="<?php echo $link_path ?>">
				<?php echo $title ?>
			</a>
			<p>
				<?php echo $sample ?>
			</p>
		</li>
		<?php } ?>
	</ul>
</div>
	<?php } ?>
<?php } ?>