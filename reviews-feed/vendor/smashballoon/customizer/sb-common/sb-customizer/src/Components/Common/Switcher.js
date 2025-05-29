import SbUtils from '../../Utils/SbUtils'

const Switcher = ( props ) => {

    const slug = 'sb-switcher-ctn',
        actionsList =  [ 'onChange' ],
        classesList =  [ 'size' ];

    return (
        <>
            <label
                className={ SbUtils.getClassNames( props, slug, classesList ) }
            >
                <input
                    type="checkbox"
                    checked={ props?.value === props?.enabled }
                    { ...SbUtils.getElementActions( props, actionsList ) }
                />
                <span className='sb-switcher-slider'></span>
                {
                    props?.label &&
                    <span className={ ( props?.labelStrong === true ? 'sb-bold ' : '' ) + 'sb-el-label sb-text-small'}
                        dangerouslySetInnerHTML={{__html:  props.label.replace(/\s/g, '&nbsp;')  }}>
                    </span>
                }
            </label>

        </>
    )

}

export default Switcher;