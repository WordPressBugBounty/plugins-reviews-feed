import SbUtils from '../../Utils/SbUtils'

const Notification = ( props ) => {
    return (
        props?.active &&
        <div className='sb-notification-ctn'
            data-active={ props?.active ? 'shown' : 'hidden' }
            data-type={ props?.type || 'success' }
        >
            {
                props?.icon &&
                SbUtils.printIcon( props.icon, 'sb-notification-icon' )
            }
            <span className='sb-notification-text'>{ props.text }</span>
        </div>
    )
}

export default Notification;