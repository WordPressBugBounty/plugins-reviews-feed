import SbUtils from '../../../Utils/SbUtils'
import Button from '../../Common/Button'

const LoadMoreButton = ( { feedSettings } ) => {

    return (
        feedSettings?.showLoadButton === true &&
        <section className='sb-load-button-ctn sb-fs'>
            <Button
                text={feedSettings?.loadButtonText}
                full-width={true}
                customClass='sb-load-button'
                size='small'
            />
            { SbUtils.addHighlighter( 'loadmore-button', 'Load More Button' ) }
        </section>
    )
}

export default LoadMoreButton;