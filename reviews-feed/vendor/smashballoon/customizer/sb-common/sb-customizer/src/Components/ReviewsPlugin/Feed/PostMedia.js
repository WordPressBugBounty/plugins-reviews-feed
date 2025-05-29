import SbUtils from '../../../Utils/SbUtils'

const PostMedia = ( { post, postIndex, feedSettings } ) => {

	const printPostMedia = () => {
		if (
			post?.media
			&& post?.media.length > 0
		) {
			return <div className='sbr-media-list sb-fs'>
				{
					post?.media.map((sMedia, medIndex) => {
						if (sMedia?.type === 'image'
							&& sMedia?.url !== undefined) {
								return (
									<div
										className='sbr-media-item'
										key={medIndex}
										style={{backgroundImage: 'url(' + sMedia?.url + ')'}}
									>
									</div>
								)
							}
					})
				}
			</div>
		} else {
			return null;
		}
	}
	return (
		printPostMedia()
	)
}

export default PostMedia;