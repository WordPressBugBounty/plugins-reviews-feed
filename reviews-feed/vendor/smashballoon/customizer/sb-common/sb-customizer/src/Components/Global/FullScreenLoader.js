import SbUtils from '../../Utils/SbUtils'

const FullScreenLoader = () => {
    return (
        <div className='sb-fs-loader'>
            <div className='sb-fs-loader-logo'>
                <div className='sb-fs-loader-spinner'></div>
                { SbUtils.printIcon( 'logo', 'sb-fs-loader-img') }
            </div>
            <div className='sb-fs-loader-txt'>
                Loading...
            </div>
        </div>
    )
}

export default FullScreenLoader;