import SbUtils from '../../Utils/SbUtils'

const Notice = ( props ) => {

    const   slug = 'sb-notice',
            actionsList =  [ 'onClick' ],
            classesList =  [
            {'type' : 'default'}
        ];


    return (
        <div
            className={ SbUtils.getClassNames( props, slug, classesList ) }
           { ...SbUtils.getElementActions( props, actionsList ) }

        >
            { SbUtils.printIcon( props.icon, 'sb-notice-icon', false, props?.iconSize ) }
            <span className='sb-text-tiny'>
                 {
                    props.heading &&
                    <strong className='sb-fs' dangerouslySetInnerHTML={{__html: props.heading }}></strong>
                }
                {
                    props.text &&
                    <span className='sb-fs' dangerouslySetInnerHTML={{__html: props.text }}></span>
                }
            </span>
        </div>
    )
}

export default Notice;