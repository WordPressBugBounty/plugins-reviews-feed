import SbUtils from '../../../Utils/SbUtils'

const PostAuthor = ( { post, postIndex, feedSettings, isPro } ) => {

    return (
        <div className='sb-item-author-date-ctn sb-fs'>
        <div className='sb-item-author-ctn sb-relative'>
            {
                ( feedSettings.authorContent.includes( 'image' ) && isPro ) &&
                <div className='sb-item-author-img sb-relative' title={post?.reviewer?.name}
                    style={{
                        backgroundImage : `url(` + post?.reviewer?.avatar || window.sb_customizer.assetsURL + 'sb-customizer/assets/images/avatar.jpg'`)`
                    }}
                >
                    { SbUtils.addHighlighter( 'post-author-image', 'Author Image' , postIndex ) }
                </div>
            }
            {
                ( feedSettings.authorContent.includes( 'name' ) || feedSettings.authorContent.includes( 'date' ) ) &&
                <div className='sb-item-name-date'>
                    {
                        feedSettings.authorContent.includes( 'name' ) &&
                        <span className='sb-item-author-name sb-relative'>
                            {post?.reviewer?.name}
                            { SbUtils.addHighlighter( 'post-author-name', 'Author Name' , postIndex ) }
                        </span>
                    }
                    {
                        feedSettings.authorContent.includes( 'date' ) &&
                        <span className='sb-item-author-date sb-relative'>
                            { feedSettings?.dateBeforeText + ' ' + SbUtils.printDate(post?.time, feedSettings ) + ' ' + feedSettings?.dateAfterText}
                            { SbUtils.addHighlighter( 'post-date', 'Date' , postIndex ) }
                        </span>
                    }
                </div>
            }
            { SbUtils.addHighlighter( 'post-author-date', 'Author and Date' , postIndex ) }
        </div>
        </div>
    )
}

export default PostAuthor;