import SinglePost from './SinglePost'

const ListLayout = ( { feedSettings, posts, isPro } ) => {
    return (
        <div className='sb-feed-wrap sb-fs'>
            {
                posts.map( (post, postIndex) =>
                    <SinglePost
                        post={post}
                        postIndex={postIndex}
                        feedSettings={feedSettings}
                        key={postIndex}
                        isPro={isPro}
                    />
                )
            }
        </div>
    )
}

export default ListLayout;