import SbUtils from '../../Utils/SbUtils'

const Textarea = ( props ) => {

    const slug = 'sb-textarea',
          actionsList =  [ 'onFocus', 'onKeyDown', 'onKeyUp', 'onChange', 'onBlur' ],
          classesList =  [ 'size' ],
          parentActionsList =  [ 'onClick'  ];


    return (
        <div
            className={'sb-textarea-ctn sb-input-ctn sb-fs' + SbUtils.getClassNames( props, slug, classesList)}
            { ...SbUtils.getElementActions( props, parentActionsList ) }
            data-disabled={ props?.disabled }

        >
            { props.label && <div className='sb-dark2-text sb-label sb-text-tiny sb-fs'>{props.label}</div> }
            <div className='sb-input-insider sb-fs'>
                <textarea
                        maxLength={ props.maxLength }
                        minLength={ props.minLength }
                        cols={ props.cols }
                        rows={ props.rows }
                        name={ props?.name }
                        value={ props?.value }
                        placeholder={ props.placeholder }
                        disabled={ props?.disabled === true}
                        { ...SbUtils.getElementActions( props, actionsList ) }
                >
                </textarea>
            </div>
            { props.description && <div className='sb-dark2-text sb-caption sb-text-tiny sb-fs'>{props.description}</div> }
        </div>
    )

}

export default Textarea;
