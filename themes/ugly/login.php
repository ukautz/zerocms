<?php if ( $zcms->isAdmin() ) { ?>
<h2>
	You are logged in!!
</h2>
<?php } else { ?>
<form class="admin-login">
	<label>
		<span>User</span>
		<input type="text" name="login" />
	</label>
	<label>
		<span>Password</span>
		<input type="password" name="password" />
	</label>
	<button type="submit">
		Login Now
	</button>
</form>
<?php } ?>