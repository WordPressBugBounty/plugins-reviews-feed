import SbUtils from '../../../Utils/SbUtils'

const PostText = ( { post, postIndex, feedSettings } ) => {

    return (
        <div className='sb-item-text sb-fs'>
            {SbUtils.printText( post?.text ?? '', feedSettings ) }
            { SbUtils.addHighlighter( 'post-text', 'Paragraph' , postIndex ) }
        </div>
    )

}

export default PostText;