<?php if ($token == false) { ?>
<div class="alert alert-warning">
<i class="fa fa-exclamation-circle"></i>
<?php echo $token_error; ?>
</div>
<?php } else { ?>
<form action="<?php echo $action; ?>" method="post">
  <input type="hidden" name="token" value="<?php echo $token; ?>" />
  <div class="buttons">
    <div class="right">
      <input type="submit" value="<?php echo $button_confirm; ?>" class="button" />
    </div>
  </div>
</form>
<?php } ?>
