import SbUtils from '../../../Utils/SbUtils'
import PostAuthor from './PostAuthor'
import PostRating from './PostRating'
import PostMedia from './PostMedia'
import PostText from './PostText'

const SinglePost = ( { post, postIndex, feedSettings, isPro } ) => {
    return (
        <div className='sb-post-item-wrap' key={postIndex}>
            <div className='sb-post-item' key={postIndex}>
                { SbUtils.printIcon( post?.provider?.name.toLowerCase() + '-provider', 'sb-item-provider-icon' ) }
                {
                    feedSettings?.postElements.map((element, key) => {
                        switch (element) {
                            case 'rating':
                                return <PostRating
                                    post={post}
                                    postIndex={postIndex}
                                    feedSettings={feedSettings}
                                    key={key}
                                    isPro={isPro}
                                />
                            case 'author':
                            return  <PostAuthor
                                    post={post}
                                    postIndex={postIndex}
                                    feedSettings={feedSettings}
                                    key={key}
                                    isPro={isPro}
                                />
                            case 'media':
                                return <PostMedia
                                    post={post}
                                    postIndex={postIndex}
                                    feedSettings={feedSettings}
                                    key={key}
                                />
                            default:
                            return <PostText
                                    post={post}
                                    postIndex={postIndex}
                                    feedSettings={feedSettings}
                                    key={key}
                                />
                        }
                    })
                }
            </div>
            { SbUtils.addHighlighter( 'reviews', 'Reviews', postIndex ) }
        </div>
    )
}

export default SinglePost;