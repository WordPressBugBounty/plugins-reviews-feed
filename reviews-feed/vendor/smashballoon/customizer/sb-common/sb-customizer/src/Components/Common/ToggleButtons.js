import SbUtils from '../../Utils/SbUtils'

const ToggleButtons = ( props ) => {

    const slug = 'sb-togglbuttons',
          parentActionsList =  [ 'parentOnClick' ],
          elementsActionsList =  [ 'onClick' ],
          classesList =  [ 'size' ];

    const onChooseElement = ( value ) => {
        props.onClick( value )
    }

    return (
        <div
            className={'sb-togglbuttons-ctn sb-fs ' + SbUtils.getClassNames( props, slug, classesList)}
            { ...SbUtils.getElementActions( props, parentActionsList ) }
        >
            {
                props?.options?.map( ( opt, optInd ) => {
                    return (
                        <div
                            className='sb-togglbuttons-elem sb-fs sb-tr-2'
                            { ...SbUtils.getElementActions( props, elementsActionsList ) }
                            key={ optInd }
                            data-active={ props?.value === opt.value }
                            onClick={ () => {
                                onChooseElement( opt.value )
                            }}
                        >
                            { SbUtils.printIcon( opt.icon ) }
                            { ( opt.label && opt.icon ) && '\u00A0\u00A0' }
                            { opt.label }
                        </div>
                    )
                })
            }

        </div>
    )
}

export default ToggleButtons;