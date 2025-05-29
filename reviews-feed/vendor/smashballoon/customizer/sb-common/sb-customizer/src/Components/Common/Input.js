import SbUtils from '../../Utils/SbUtils'

const Input = ( props ) => {

    const slug = 'sb-input',
          actionsList =  [ 'onFocus', 'onKeyDown', 'onKeyUp', 'onKeyPress', 'onChange', 'onBlur'  ],
          classesList =  [ 'size' ],
          attributesList = [ 'disablebg', 'disableleading-brd', 'disabletrailing-brd' ],
          parentActionsList =  [ 'onClick'  ];

    return (
        <div
            className={'sb-input-ctn sb-fs ' + SbUtils.getClassNames( props, slug, classesList)}
            { ...SbUtils.getElementAttributes( props, attributesList ) }
            style={ props?.style }
            data-disabled={ props?.disabled }
            { ...SbUtils.getElementActions( props, parentActionsList ) }
        >
            {
                props.label &&
                <div className='sb-dark2-text sb-label sb-text-tiny sb-fs'>{props.label}</div>
            }
            <div className='sb-input-insider sb-fs'>
                {
                    ( props.leadingText || props.leadingIcon ) &&
                    <span className='sb-input-leading-txt sb-dark2-text'>
                        { SbUtils.printIcon( props.leadingIcon ) }
                        { ( props.leadingText && props.leadingIcon ) && '\u00A0\u00A0' }
                        { props.leadingText }
                    </span>
                }
                <input
                    type={ props.type || 'text' }
                    minLength={ props.minLength }
                    maxLength={ props.maxLength }
                    min={ props.min }
                    max={ props.max }
                    placeholder={ props.placeholder }
                    name={ props?.name }
                    value={ props?.value }
                    disabled={ props?.disabled === true}
                    { ...SbUtils.getElementActions( props, actionsList ) }
                />
                {
                    ( props.trailingText || props.trailingIcon) &&
                    <span className='sb-input-trailing-txt sb-dark2-text'>
                        { SbUtils.printIcon( props.trailingIcon ) }
                        { ( props.trailingText && props.trailingIcon ) && '\u00A0\u00A0' }
                        { props.trailingText }
                    </span>
                }
            </div>
            { props.description && <div className='sb-dark2-text sb-caption sb-text-tiny sb-fs'>{props.description}</div> }
        </div>
    )

}

export default Input;
