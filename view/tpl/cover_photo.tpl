<div id="profile-photo-content" class="generic-content-wrapper">
    <div class="section-title-wrapper">
    <h2>{{$title}}</h2>
    </div>
    <div class="section-content-wrapper">

		<form enctype="multipart/form-data" action="cover_photo" method="post">
		<input type='hidden' name='form_security_token' value='{{$form_security_token}}'>

		<div id="profile-photo-upload-wrapper">

			<label id="profile-photo-upload-label" class="form-label" for="profile-photo-upload">{{$lbl_upfile}}</label>
			<input name="userfile" class="form-input" type="file" id="profile-photo-upload" size="48" />
			<div class="clear"></div>
			<br />
			<br />
			<div id="profile-photo-submit-wrapper">
				<input type="submit" name="submit" id="profile-photo-submit" value="{{$submit}}">
			</div>
		</div>

		</form>
		<br />
		<div id="profile-photo-link-select-wrapper">
		<button id="embed-photo-wrapper" class="btn btn-default btn-primary" title="{{$embedPhotos}}" onclick="initializeEmbedPhotoDialog();return false;">
		<i id="embed-photo" class="fa fa-file-image-o"></i> {{$select}}
		</button>
		</div>
	</div>
</div>
<div class="modal" id="embedPhotoModal" tabindex="-1" role="dialog" aria-labelledby="embedPhotoLabel" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="embedPhotoModalLabel">{{$embedPhotosModalTitle}}</h4>
				<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
			</div>
			<div class="modal-body" id="embedPhotoModalBody" >
				<div id="embedPhotoModalBodyAlbumListDialog" class="d-none">
					<div id="embedPhotoModalBodyAlbumList"></div>
				</div>
				<div id="embedPhotoModalBodyAlbumDialog" class="d-none"></div>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->
